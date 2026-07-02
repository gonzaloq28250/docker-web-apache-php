<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AVI Dashboards - ICQ24</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        icq: {
                            green: '#397b21',
                            'green-light': '#4a9e2a',
                            dark: '#1a1a2e',
                            darker: '#0f0f1e',
                            card: '#16213e'
                        }
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-gradient-to-br from-icq-darker via-icq-dark to-slate-900 min-h-screen flex items-center justify-center">
    <div class="max-w-4xl mx-auto px-6 py-12">
        <div class="text-center mb-12">
            <a href="https://www.icq24.com" target="_blank" class="inline-block mb-6">
                <img src="https://www.icq24.com/web/image/website/5/logo/ICQ24%20Oficial%20WebSite"
                     alt="ICQ24"
                     class="h-12 brightness-0 invert opacity-90 hover:opacity-100 transition-opacity">
            </a>
            <h1 class="text-4xl font-bold text-white mb-3">AVI Dashboards</h1>
            <p class="text-lg text-gray-400">
                <i class="fas fa-chevron-right text-icq-green text-sm mr-2"></i>
                Panel de administración de dashboards y evaluaciones
            </p>
        </div>

        <div class="grid md:grid-cols-3 gap-6">
            <a href="https://az-icq-webserver.eastus2.cloudapp.azure.com/f9-11labs/AVI-Dashboard/dashboard_consolidado.php" target="_blank"
               class="group bg-icq-card/80 backdrop-blur hover:bg-icq-card rounded-2xl p-8 transition-all duration-300 hover:scale-[1.02] hover:shadow-2xl hover:shadow-icq-green/10 border border-white/5 hover:border-icq-green/30">
                <div class="w-14 h-14 bg-icq-green/20 rounded-xl flex items-center justify-center mb-5 group-hover:bg-icq-green/30 transition-colors">
                    <i class="fas fa-chart-line text-2xl text-icq-green"></i>
                </div>
                <h2 class="text-xl font-bold text-white mb-2">Dashboard NEQUI-Eleven</h2>
                <p class="text-gray-400 text-sm leading-relaxed">Métricas en tiempo real, costos, duración y análisis de llamadas de ElevenLabs</p>
                <div class="mt-4 flex items-center text-icq-green text-sm font-medium group-hover:gap-2 transition-all">
                    <span>Acceder</span>
                    <i class="fas fa-arrow-right ml-2 group-hover:ml-3 transition-all"></i>
                </div>
            </a>

            <a href="https://az-icq-webserver.eastus2.cloudapp.azure.com/f9-11labs/AVI-Dashboard/AVI-Dashboard-level/dashboard_consolidado.php" target="_blank"
               class="group bg-icq-card/80 backdrop-blur hover:bg-icq-card rounded-2xl p-8 transition-all duration-300 hover:scale-[1.02] hover:shadow-2xl hover:shadow-icq-green/10 border border-white/5 hover:border-icq-green/30">
                <div class="w-14 h-14 bg-blue-500/20 rounded-xl flex items-center justify-center mb-5 group-hover:bg-blue-500/30 transition-colors">
                    <i class="fas fa-chart-pie text-2xl text-blue-400"></i>
                </div>
                <h2 class="text-xl font-bold text-white mb-2">Dashboard NEQUI / NEQUI2</h2>
                <p class="text-gray-400 text-sm leading-relaxed">Dashboard consolidado con KPIs, gráficos y reportes históricos para clientes NEQUI</p>
                <div class="mt-4 flex items-center text-blue-400 text-sm font-medium group-hover:gap-2 transition-all">
                    <span>Acceder</span>
                    <i class="fas fa-arrow-right ml-2 group-hover:ml-3 transition-all"></i>
                </div>
            </a>

            <a href="https://az-icq-webserver.eastus2.cloudapp.azure.com/f9-11labs/AVI-Dashboard/AVI-Evaluaciones/dashboard_evaluacion.php" target="_blank"
               class="group bg-icq-card/80 backdrop-blur hover:bg-icq-card rounded-2xl p-8 transition-all duration-300 hover:scale-[1.02] hover:shadow-2xl hover:shadow-icq-green/10 border border-white/5 hover:border-icq-green/30">
                <div class="w-14 h-14 bg-amber-500/20 rounded-xl flex items-center justify-center mb-5 group-hover:bg-amber-500/30 transition-colors">
                    <i class="fas fa-clipboard-check text-2xl text-amber-400"></i>
                </div>
                <h2 class="text-xl font-bold text-white mb-2">Evaluaciones</h2>
                <p class="text-gray-400 text-sm leading-relaxed">Panel de evaluación de transcripciones con estadísticas, matriz de resultados y exportación</p>
                <div class="mt-4 flex items-center text-amber-400 text-sm font-medium group-hover:gap-2 transition-all">
                    <span>Acceder</span>
                    <i class="fas fa-arrow-right ml-2 group-hover:ml-3 transition-all"></i>
                </div>
            </a>

            <a href="https://az-icq-webserver.eastus2.cloudapp.azure.com/f9-11labs/AVI-Dashboard/AVI-Evaluaciones/reporte_concurrencia.php" target="_blank"
               class="group bg-icq-card/80 backdrop-blur hover:bg-icq-card rounded-2xl p-8 transition-all duration-300 hover:scale-[1.02] hover:shadow-2xl hover:shadow-icq-green/10 border border-white/5 hover:border-icq-green/30">
                <div class="w-14 h-14 bg-teal-500/20 rounded-xl flex items-center justify-center mb-5 group-hover:bg-teal-500/30 transition-colors">
                    <i class="fas fa-people-arrows text-2xl text-teal-400"></i>
                </div>
                <h2 class="text-xl font-bold text-white mb-2">Concurrencia</h2>
                <p class="text-gray-400 text-sm leading-relaxed">Reporte de llamadas concurrentes por día con máximos, promedios y factor de uso</p>
                <div class="mt-4 flex items-center text-teal-400 text-sm font-medium group-hover:gap-2 transition-all">
                    <span>Acceder</span>
                    <i class="fas fa-arrow-right ml-2 group-hover:ml-3 transition-all"></i>
                </div>
            </a>
        </div>

        <div class="text-center mt-12 text-gray-600 text-xs">
            <a href="https://www.icq24.com" target="_blank" class="hover:text-gray-400 transition-colors">ICQ24</a>
            <span class="mx-2">·</span>
            <span>AVI Dashboards</span>
            <span class="mx-2">·</span>
            <span>v1.0</span>
        </div>
    </div>
</body>
</html>
