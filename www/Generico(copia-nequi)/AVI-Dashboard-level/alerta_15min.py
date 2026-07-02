#!/usr/bin/env python3
"""
Valida el porcentaje de llamadas con duracion <= umbral en intervalos de 15 min.
Si supera el porcentaje configurado, envia alerta por email via Microsoft Graph.
"""

import json
import logging
import sys
from datetime import datetime, timedelta
from pathlib import Path
from zoneinfo import ZoneInfo

import requests
import pymysql
from logging.handlers import TimedRotatingFileHandler


def setup_logging(log_file: str):
    logger = logging.getLogger()
    logger.setLevel(logging.INFO)

    fmt = logging.Formatter(
        "%(asctime)s [%(levelname)s] %(message)s",
        datefmt="%Y-%m-%d %H:%M:%S",
    )

    consola = logging.StreamHandler()
    consola.setFormatter(fmt)
    logger.addHandler(consola)

    archivo = TimedRotatingFileHandler(
        log_file, when="D", interval=7, encoding="utf-8", backupCount=4
    )
    archivo.setFormatter(fmt)
    logger.addHandler(archivo)


def load_config(path: str) -> dict:
    with open(path, "r", encoding="utf-8") as f:
        return json.load(f)


def query_db(config: dict, dt_inicio: datetime, dt_fin: datetime) -> dict:
    q = config["query"]
    db = config["db"]
    umbral = q["umbral_seg"]

    conn = pymysql.connect(
        host=db["host"],
        database=db["database"],
        user=db["user"],
        password=db["password"],
        charset=db.get("charset", "utf8mb4"),
        cursorclass=pymysql.cursors.DictCursor,
    )

    resultados_por_cliente = {}

    try:
        with conn.cursor() as cur:
            for cliente in q["clientes"]:
                cur.execute(
                    """
                    SELECT
                        SUM(CASE WHEN duration <= %(umbral)s THEN 1 ELSE 0 END) AS menor_igual_u,
                        SUM(CASE WHEN duration > %(umbral)s THEN 1 ELSE 0 END) AS mayor_u,
                        COUNT(*) AS total
                    FROM level_calls
                    WHERE cliente = %(cliente)s
                      AND F9TimeStamp >= %(inicio)s
                      AND F9TimeStamp < %(fin)s
                    """,
                    {
                        "umbral": umbral,
                        "cliente": cliente,
                        "inicio": dt_inicio.strftime("%Y-%m-%d %H:%M:%S"),
                        "fin": dt_fin.strftime("%Y-%m-%d %H:%M:%S"),
                    },
                )
                row = cur.fetchone()
                total = row["total"] or 0
                menor = row["menor_igual_u"] or 0
                pct = (menor / total * 100) if total > 0 else 0.0
                resultados_por_cliente[cliente] = {
                    "total": total,
                    "menor_igual_u": menor,
                    "mayor_u": row["mayor_u"] or 0,
                    "porcentaje": round(pct, 2),
                }
                logging.info(
                    "%s: total=%d, <=%ds=%d (%.2f%%), >%ds=%d",
                    cliente,
                    total,
                    umbral,
                    menor,
                    pct,
                    umbral,
                    row["mayor_u"] or 0,
                )
    finally:
        conn.close()

    return {
        "fecha": dt_inicio.strftime("%Y-%m-%d"),
        "inicio": dt_inicio.strftime("%H:%M"),
        "fin": dt_fin.strftime("%H:%M"),
        "umbral": umbral,
        "clientes": resultados_por_cliente,
    }


def build_email_body(resultados: dict, config: dict) -> str:
    q = config["query"]
    url_base = config["reporte_url"]
    umbral = q["umbral_seg"]
    pct_limite = q["pct_notificacion"]
    lines = [
        f"Reporte de llamadas cortas - {resultados['fecha']}",
        f"Intervalo: {resultados['inicio']} - {resultados['fin']}",
        "",
        f"Umbral: ≤{umbral}s | Límite de alerta: >{pct_limite}%",
        "",
    ]
    alguno_alerta = False
    for cliente, data in resultados["clientes"].items():
        alerta = data["porcentaje"] > pct_limite
        if alerta:
            alguno_alerta = True
        lines.append(f"Cliente: {cliente}")
        lines.append(f"  Total llamadas:  {data['total']}")
        lines.append(f"  Duración ≤{umbral}s: {data['menor_igual_u']} ({data['porcentaje']}%)")
        lines.append(f"  Duración >{umbral}s: {data['mayor_u']}")
        lines.append(f"  {'⚠ ALERTA' if alerta else 'OK'} (límite {pct_limite}%)")
        lines.append("")

    lines.append("Enlaces de detalle:")
    for cliente in q["clientes"]:
        url = (
            f"{url_base}?cliente={cliente}"
            f"&fecha={resultados['fecha']}"
            f"&umbral={umbral}"
        )
        lines.append(f"  {cliente}: {url}")

    if not alguno_alerta:
        lines.append("")
        lines.append("Ningun cliente supero el limite de alerta.")

    return "\n".join(lines)


def send_email(config: dict, subject: str, body: str):
    if config["email"].get("dry_run", False):
        logging.info("[DRY-RUN] Email no enviado (dry_run activo).")
        logging.info("Subject: %s", subject)
        logging.info("Body:\n%s", body)
        return

    if not config["email"].get("enabled", True):
        logging.info("Email deshabilitado por config.")
        return

    g = config["graph"]
    token_url = f"https://login.microsoftonline.com/{g['tenant_id']}/oauth2/v2.0/token"
    token_data = {
        "grant_type": "client_credentials",
        "client_id": g["client_id"],
        "client_secret": g["client_secret"],
        "scope": "https://graph.microsoft.com/.default",
    }

    resp = requests.post(token_url, data=token_data)
    token = resp.json()
    access_token = token.get("access_token")
    if not access_token:
        raise Exception(f"Error obteniendo token: {token}")

    to_list = [{"emailAddress": {"address": addr}} for addr in config["email"]["to"]]
    msg = {
        "message": {
            "subject": subject,
            "body": {"contentType": "Text", "content": body},
            "toRecipients": to_list,
        }
    }

    email_url = f"https://graph.microsoft.com/v1.0/users/{g['from']}/sendMail"
    headers = {
        "Authorization": f"Bearer {access_token}",
        "Content-Type": "application/json",
    }

    r = requests.post(email_url, headers=headers, json=msg)
    if r.status_code != 202:
        raise Exception(f"Error enviando email ({r.status_code}): {r.text}")

    logging.info("Email enviado exitosamente a %s", config["email"]["to"])


def main():
    config_path = Path(__file__).with_name("alerta_15min_config.json")
    if len(sys.argv) > 1:
        config_path = Path(sys.argv[1])

    if not config_path.exists():
        print(f"ERROR: Config no encontrada: {config_path}", file=sys.stderr)
        sys.exit(1)

    config = load_config(str(config_path))

    log_file = config.get("log_file", str(Path(__file__).with_name("alerta_15min.log")))
    setup_logging(log_file)

    q = config["query"]
    logging.info("Config cargada: umbral=%ds, pct_limite=%.1f%%",
                 q["umbral_seg"], q["pct_notificacion"])

    # Calcular intervalo anterior de 15 min completo (hora Colombia)
    tz_col = ZoneInfo("America/Bogota")
    ahora = datetime.now(tz_col)
    minutos_bloque = (ahora.minute // 15) * 15
    fin_bloque_actual = ahora.replace(minute=minutos_bloque, second=0, microsecond=0)
    dt_fin = fin_bloque_actual
    dt_inicio = dt_fin - timedelta(minutes=15)

    # Validar horario (el intervalo debe terminar entre 7:01 y 22:00)
    hora_inicio = q.get("hora_inicio", 7)
    hora_fin = q.get("hora_fin", 22)
    if dt_fin.hour < hora_inicio or (dt_fin.hour == hora_inicio and dt_fin.minute == 0) or dt_fin.hour > hora_fin:
        logging.info(
            "Fuera de horario (intervalo %s-%s). Sin alerta.",
            dt_inicio.strftime("%H:%M"), dt_fin.strftime("%H:%M"),
        )
        return

    resultados = query_db(config, dt_inicio, dt_fin)
    pct_limite = q["pct_notificacion"]
    disparar_alerta = any(
        d["porcentaje"] > pct_limite for d in resultados["clientes"].values()
    )
    dry_run = config["email"].get("dry_run", False)

    enviar_siempre = config.get("email", {}).get("enviar_siempre", False)
    deberia_enviar = disparar_alerta or dry_run or enviar_siempre
    if deberia_enviar:
        motivo = "enviar_siempre" if enviar_siempre else ("dry_run" if dry_run else "disparada")
        logging.info("Alerta %s. Enviando email...", motivo)
        body = build_email_body(resultados, config)
        subject = f"{config['email']['subject']} [{resultados['inicio']}-{resultados['fin']}]"
        send_email(config, subject, body)
    else:
        logging.info("Ningun cliente supero el %.1f%% de limite. Sin alerta.", pct_limite)


if __name__ == "__main__":
    main()
