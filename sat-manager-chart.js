jQuery(document).ready(function($) {
    
    // Verificar si satChartData está disponible (pasado desde PHP)
    if (typeof satChartData === 'undefined') {
        console.error('SaleAlToque Manager: Los datos para los gráficos (satChartData) no están disponibles.');
        return; // No continuar si no hay datos
    }

    // Configuración común para todos los gráficos
    const commonOptions = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                display: false // Leyenda general desactivada, se activa por gráfico si es necesario
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    precision: 0,
                    font: {
                        size: 11
                    }
                }
            },
            x: {
                ticks: {
                    font: {
                        size: 11
                    }
                }
            }
        }
    };

    // Colores para los gráficos (sin cambios)
    const colors = {
        primary: '#0073aa',
        secondary: '#00a0d2',
        accent: '#72aee6',
        success: '#46b450',
        warning: '#ffb900'
    };

    // 1. Gráfico de usuarios activos (donut compacto)
    const ctxUsuarios = document.getElementById('usuarios-activos-chart');
    if (ctxUsuarios && typeof satChartData.loggedInActive !== 'undefined' && typeof satChartData.guestActive !== 'undefined') {
        new Chart(ctxUsuarios, {
            type: 'doughnut',
            data: {
                labels: ['Usuarios logueados', 'Visitantes'],
                datasets: [{
                    data: [satChartData.loggedInActive, satChartData.guestActive],
                    backgroundColor: [colors.primary, colors.secondary],
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: { // Opciones específicas para este gráfico de dona
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: true,
                        position: 'bottom',
                        labels: {
                            font: {
                                size: 11
                            },
                            padding: 10
                        }
                    }
                }
            }
        });
    } else if (ctxUsuarios) {
        console.warn('SaleAlToque Manager: Faltan datos para el gráfico de usuarios activos.');
    }

    // 2. Gráfico de actividad por días
    const ctxDias = document.getElementById('chart-dias');
    if (ctxDias && satChartData.actividadDias && satChartData.actividadDias.labels && satChartData.actividadDias.data) {
        new Chart(ctxDias, {
            type: 'bar',
            data: {
                labels: satChartData.actividadDias.labels, // Labels vienen de PHP
                datasets: [{
                    label: 'Eventos por día', // Añadido label para el dataset
                    data: satChartData.actividadDias.data, // Data viene de PHP
                    backgroundColor: colors.accent,
                    borderColor: colors.primary,
                    borderWidth: 1
                }]
            },
            options: { // Usa commonOptions y puede extenderlas
                ...commonOptions,
                plugins: { // Reactivar leyenda si es necesario o modificarla
                    legend: {
                        display: true, // Mostrar leyenda para este gráfico si se desea (ej. 'Eventos por día')
                        position: 'top',
                    }
                },
                scales: {
                    ...commonOptions.scales,
                    y: {
                        ...commonOptions.scales.y,
                        title: {
                            display: true,
                            text: 'Total Eventos',
                            font: { size: 11 }
                        }
                    }
                }
            }
        });
    } else if (ctxDias) {
        console.warn('SaleAlToque Manager: Faltan datos para el gráfico de actividad por días.');
    }

    // 3. Gráfico de actividad por horas
    const ctxHoras = document.getElementById('chart-horas');
    if (ctxHoras && satChartData.actividadHoras && satChartData.actividadHoras.labels && satChartData.actividadHoras.data) {
        new Chart(ctxHoras, {
            type: 'line',
            data: {
                labels: satChartData.actividadHoras.labels, // Labels vienen de PHP
                datasets: [{
                    label: 'Eventos por hora', // Añadido label para el dataset
                    data: satChartData.actividadHoras.data, // Data viene de PHP
                    borderColor: colors.success,
                    backgroundColor: colors.success + '20', // Ligera transparencia
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4,
                    pointRadius: 3,
                    pointBackgroundColor: colors.success
                }]
            },
            options: { // Usa commonOptions y puede extenderlas
                ...commonOptions,
                 plugins: { 
                    legend: {
                        display: true, 
                        position: 'top',
                    }
                },
                scales: {
                    ...commonOptions.scales,
                    y: {
                        ...commonOptions.scales.y,
                        title: {
                            display: true,
                            text: 'Total Eventos',
                            font: { size: 11 }
                        }
                    },
                    x: {
                        ...commonOptions.scales.x,
                        title: {
                            display: true,
                            text: 'Hora del día',
                            font: { size: 11 }
                        }
                    }
                }
            }
        });
    } else if (ctxHoras) {
        console.warn('SaleAlToque Manager: Faltan datos para el gráfico de actividad por horas.');
    }

    // Actualización de datos (funcionalidad AJAX no implementada)
    // El siguiente setInterval está aquí como un marcador de posición.
    // Para una actualización real, necesitarías implementar una llamada AJAX
    // a un endpoint de WordPress que devuelva los datos actualizados.
    /*
    setInterval(function() {
        if (satChartData && satChartData.isPluginPage) {
            // console.log('Verificando actualizaciones de gráficos...');
            // TODO: Implementar llamada AJAX para obtener datos actualizados y actualizar los gráficos.
            // Ejemplo:
            // $.ajax({
            //     url: ajaxurl, // ajaxurl está disponible globalmente en las páginas de admin de WP
            //     type: 'POST',
            //     data: {
            //         action: 'sat_get_updated_chart_data', // Deberás definir esta acción en PHP
            //         _ajax_nonce: 'TU_NONCE_AQUI' // Importante por seguridad
            //     },
            //     success: function(response) {
            //         if (response.success) {
            //             // Actualizar datos del gráfico de usuarios activos
            //             // chartUsuarios.data.datasets[0].data = [response.data.loggedInActive, response.data.guestActive];
            //             // chartUsuarios.update();
            //             // ... actualizar otros gráficos ...
            //         }
            //     }
            // });
        }
    }, 30000); // 30 segundos
    */
});
