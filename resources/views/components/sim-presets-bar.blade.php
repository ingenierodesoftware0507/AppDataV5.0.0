@props(['vehiculos' => [], 'modelos' => [], 'norma' => 'NTC / Res. 910'])
<div class="col-12 mb-3">
    <div class="card">
        <div class="card-header py-1">📋 Valores predefinidos ({{ $norma }})</div>
        <div class="card-body py-2">
            <div class="row g-2 align-items-end">
                <div class="col-12 col-md-4">
                    <label for="sim_preset_vehiculo" class="form-label small mb-1">Vehículo</label>
                    <select class="form-select form-select-sm" id="sim_preset_vehiculo">
                        <option value="">-- Personalizado --</option>
                        @foreach ($vehiculos as $valor => $texto)
                            <option value="{{ $valor }}">{{ $texto }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-md-4">
                    <label for="sim_preset_modelo" class="form-label small mb-1">Modelo</label>
                    <select class="form-select form-select-sm" id="sim_preset_modelo">
                        <option value="">-- Seleccione --</option>
                        @foreach ($modelos as $valor => $texto)
                            <option value="{{ $valor }}">{{ $texto }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-md-4">
                    <small class="text-muted d-block" id="sim_preset_info">Los valores cargados quedan dentro del límite. Puede editarlos.</small>
                    <button type="button" id="btnLimpiarGuardado" class="btn btn-sm btn-outline-secondary mt-1">
                        <i class="bi bi-arrow-counterclockwise"></i> Olvidar datos guardados
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
