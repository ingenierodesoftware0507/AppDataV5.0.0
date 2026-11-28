@include('layout.heder')

<main id="main">
    <section id="visor" class="contact">
        <div class="container">
            <div class="section-title">
                <h2>Actualizar sistema</h2>
            </div>

            <div class="row" data-aos="fade-in">

                <div class="col-lg-12 mt-12 mt-lg-12 d-flex align-items-stretch">
                    <form action="{{ url('/update') }}" method="POST" class="form-control">
                        @csrf
                        <div style="margin-top: 15px">
                            <div class="input-group mb-3">
                                <label class="input-group-text" for="selComponente">¿Qué desea actualizar?</label>
                                <select class="form-select" id="selComponente" style="height: 58px">
                                    <option value="frontend">Frontend (AppData)</option>
                                    {{-- Solo si la licencia tiene activo aplicaresolucion (mismo criterio que los simuladores) --}}
                                    @if (session('licencia.aplicaresolucion', '1') !== '0')
                                        <option value="backend">Backend (appdatamachine)</option>
                                    @endif
                                </select>
                            </div>
                        </div>

                        <div id="panelBackend" style="margin-top: 15px; display: none">
                            <div class="alert alert-warning">
                                Se detendrá el servicio del backend unos segundos mientras se reemplazan los archivos.
                                No haga pruebas en pista durante la actualización. Si algo falla, se restaura
                                automáticamente la versión anterior.
                            </div>
                            <p>Versión instalada: <b id="verBackend">consultando...</b></p>
                            <div style="text-align: center">
                                <button type="button" style="height: 55px; width: 220px" class="btn btn-outline-success"
                                    id="btn-actualizar-backend">Actualizar backend</button>
                            </div>
                            <p id="estadoBackend" class="mt-3" style="text-align: center"></p>
                        </div>

                        <div id="panelFrontend" style="margin-top: 15px">
                            <div class="row">


                                <div class="col-sm-12 col-md-6 col-lg-6">
                                    <div class="input-group mb-3" style="align-content: center;">
                                        <label class="input-group-text" for="inputGroupSelect01">Seleccione la
                                            version</label>
                                        <select class="form-select selVersion" id="inputGroupSelect01"
                                            style="height: 58px" >
                                            

                                        </select>
                                    </div>
                                </div>
                                <div class="col-sm-12 col-md-6 col-lg-6">
                                    <div style="text-align: center">
                                        <button style="height: 55px; width: 150px" class="btn btn-outline-success"
                                            id="btn-descargar">Descargar</button>

                                    </div>
                                </div>
                            </div>
                        </div>
                        <div style="margin-top: 15px" id="panelDescripcion">
                            <div class="row">
                                <div class="col-sm-12 col-md-12 col-lg-12" id="descipcionActualizacion">


                                </div>

                            </div>
                        </div>





                    </form>
                </div>
            </div>
        </div>
    </section>
    <!-- ======= Contact Section ======= -->
</main>






@include('layout.footer')
<script type="text/javascript">
    const Toast = Swal.mixin({
        toast: true,
        position: "top-end",
        showConfirmButton: false,
        timer: 5000,
        timerProgressBar: true,
        didOpen: (toast) => {
            toast.onmouseenter = Swal.stopTimer;
            toast.onmouseleave = Swal.resumeTimer;
        }
    });

    $(document).ready(function() {
        $.ajax({
            url: 'https://appdataingeniersoftware.com/appdatacontrol/index.php/Cdispositivo/getActualizaciones',
            type: 'get',
            dataType: 'json',
            success: function(data, textStatus, jqXHR) {
                console.log(data)
                $(".selVersion").empty();
                
                $(".selVersion").append($('<option>', {
                    value: '',
                    text: 'Seleccione'
                }));
                $.each(data, function(index, value) {
                    $(".selVersion").append($('<option>', {
                        value: value.file,
                        text: value.file
                    }).attr('data-url', value.url || ''));
                });

            },
            error: function(jqXHR, textStatus, errorThrown) {

            }
        });

    });

    // ---- Backend (appdatamachine) ----
    let pollBackend = null;

    $("#selComponente").change(function() {
        const backend = $(this).val() === 'backend';
        $("#panelBackend").toggle(backend);
        $("#panelFrontend, #panelDescripcion").toggle(!backend);
        if (backend) {
            consultarEstadoBackend();
        }
    });

    function consultarEstadoBackend() {
        $.post('estadoBackend', {
            _token: $("input[name='_token']").val()
        }, function(d) {
            $("#verBackend").text(d.versionInstalada || 'no disponible');
            const enCurso = d.estado === 'en_proceso';
            $("#btn-actualizar-backend").prop('disabled', enCurso);
            if (d.estado === 'sin_datos') {
                $("#estadoBackend").text('');
            } else {
                const clase = d.estado === 'ok' ? 'text-success' : (d.estado === 'error' ? 'text-danger' : 'text-primary');
                $("#estadoBackend").attr('class', 'mt-3 ' + clase).text(d.mensaje || '');
            }
            if (enCurso && !pollBackend) {
                pollBackend = setInterval(consultarEstadoBackend, 3000);
            } else if (!enCurso && pollBackend) {
                clearInterval(pollBackend);
                pollBackend = null;
            }
        }, 'json');
    }

    $("#btn-actualizar-backend").click(function() {
        Swal.fire({
            title: '¿Actualizar el backend?',
            text: 'El servicio se detendrá unos segundos. No debe haber pruebas en curso.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Actualizar',
            cancelButtonText: 'Cancelar'
        }).then(function(r) {
            if (!r.isConfirmed) return;
            $.ajax({
                url: 'actualizarBackend',
                type: 'post',
                dataType: 'json',
                data: {
                    _token: $("input[name='_token']").val()
                },
                success: consultarEstadoBackend,
                error: function(jqXHR) {
                    const m = (jqXHR.responseJSON && jqXHR.responseJSON.message) || 'No se pudo iniciar la actualización';
                    Toast.fire({
                        icon: 'error',
                        title: m
                    });
                }
            });
        });
    });

    $(".selVersion").change(function(e) {
        e.preventDefault();
        // console.log($('.selVersion option:selected').attr('value'))
        // $('.selVersion option:selected').attr('value');
        $.ajax({
            url: 'https://appdataingeniersoftware.com/appdatacontrol/index.php/Cdispositivo/getActualizacionesFile',
            type: 'post',
            dataType: 'json',
            data:{
                file: $('.selVersion option:selected').attr('value'),
            },
            success: function(data, textStatus, jqXHR) {
                console.log({data})
                $("#descipcionActualizacion").html('');
                $("#descipcionActualizacion").html(data[0].descripcion);

            },
            error: function(jqXHR, textStatus, errorThrown) {

            }
        });


    });


    $("#btn-descargar").click(function(ev) {
        ev.preventDefault();
        const downloadingAlert = Swal.fire({
            title: 'Descargando...',
            text: 'La descarga de la actualización está en proceso.',
            allowOutsideClick: false,
            allowEscapeKey: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });
        $.ajax({
            url: 'getActualizacion/',
            type: 'post',
            dataType: 'json',
            data: {

                file: $(".selVersion option:selected").attr('value'),
                url: $(".selVersion option:selected").attr('data-url') || '',
                _token: $("input[name='_token']").val()
            },
            success: function(data, textStatus, jqXHR) {
                // console.log(data)
                downloadingAlert.close();
                if (data.success) {
                    if (data.versionReportada === false) {
                        Toast.fire({
                            icon: 'warning',
                            title: 'Descarga exitosa, pero no se pudo informar la versión al servidor'
                        });
                    } else {
                        Toast.fire({
                            icon: 'success',
                            title: 'Descarga exitosa'
                        });
                    }
                    // window.location.href = ;
                } else {
                    Toast.fire({
                        icon: 'error',
                        title: 'Error al descargar la actualización'
                    });
                }

            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.log('error')
                console.log(jqXHR.responseText)
                console.log(textStatus)
                console.log(errorThrown)
            }
        });


    });
</script>
