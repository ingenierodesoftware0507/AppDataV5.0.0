@props(['grupo' => 'livianos'])

{{-- SPEC 08: periféricos asociados al equipo elegido (se llenan desde public/assets/data/perifericos.js).
     Oculto mientras no haya periféricos; los campos viajan con el formulario de la prueba. --}}
<div id="perifericosSelector" data-grupo="{{ $grupo }}" class="border rounded p-3 mb-3" style="display: none;">
<h6 class="mb-3 pb-2 border-bottom"><i class="bi bi-cpu"></i> Periféricos</h6>
<div class="row">
    <div class="col-sm-12 col-md-4 col-lg-4 mb-2" id="itemPeriRPM" style="display: none;">
        <div class="input-group">
            <label class="input-group-text" for="selPeriRPM">Sensor RPM</label>
            <select class="form-select" name="selPeriRPM" id="selPeriRPM"></select>
        </div>
    </div>
    <div class="col-sm-12 col-md-4 col-lg-4 mb-2" id="itemPeriTMP" style="display: none;">
        <div class="input-group">
            <label class="input-group-text" for="selPeriTMP">Sonda temp.</label>
            <select class="form-select" name="selPeriTMP" id="selPeriTMP"></select>
        </div>
    </div>
    <div class="col-sm-12 col-md-4 col-lg-4 mb-2" id="itemPeriCaptador" style="display: none;">
        <div class="input-group">
            <label class="input-group-text" for="selPeriCaptador">Captador</label>
            <select class="form-select" name="selPeriCaptador" id="selPeriCaptador"></select>
        </div>
    </div>
</div>
</div>
<script src="{{ asset('assets/data/perifericos.js') }}?v={{ time() }}"></script>
