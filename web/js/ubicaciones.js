/* Catálogo servido por MultiPro: ninguna consulta del formulario sale a Georef. */
(() => {
    const cache = new Map();
    const normalizar = texto => texto.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('es');
    function obtener(provincia) {
        if (!cache.has(provincia)) {
            const promesa = fetch(`ubicaciones.php?provincia_id=${encodeURIComponent(provincia)}`, {headers: {Accept: 'application/json'}})
                .then(async respuesta => {
                    if (!respuesta.ok) throw new Error('No se pudieron cargar las localidades.');
                    const json = await respuesta.json();
                    if (!Array.isArray(json.data)) throw new Error('Respuesta de catálogo no válida.');
                    return json.data;
                }).catch(error => {cache.delete(provincia); throw error;});
            cache.set(provincia, promesa);
        }
        return cache.get(provincia);
    }
    document.querySelectorAll('.ubicacion-entidad').forEach(grupo => {
        const provincia = grupo.querySelector('.ubicacion-provincia');
        const localidad = grupo.querySelector('.ubicacion-localidad');
        const busqueda = grupo.querySelector('.ubicacion-busqueda');
        const estado = grupo.querySelector('.ubicacion-estado');
        let opciones = [], solicitud = 0;
        function mostrar() {
            const elegida = localidad.value;
            const filtro = normalizar(busqueda.value.trim());
            localidad.replaceChildren(new Option('Seleccioná una localidad', ''));
            opciones.filter(o => String(o.id) === elegida || normalizar(o.nombre).includes(filtro)).forEach(o => {
                localidad.add(new Option(o.nombre + (o.oficial ? '' : ' · Carga manual'), String(o.id), false, String(o.id) === elegida));
            });
            localidad.value = elegida;
        }
        async function cargar(cambio) {
            const actual = ++solicitud;
            const id = provincia.value;
            if (cambio) {busqueda.value = ''; localidad.replaceChildren(new Option('Seleccioná una localidad', ''));}
            opciones = [];
            if (!id) {localidad.replaceChildren(new Option('Seleccioná una provincia', '')); estado.textContent = 'Podés dejar ambos datos vacíos si todavía no conocés la ubicación.'; return;}
            estado.textContent = 'Cargando localidades…';
            try {
                const datos = await obtener(id);
                if (actual !== solicitud) return;
                opciones = datos; mostrar();
                estado.textContent = `${datos.length} localidades disponibles. Podés buscar por nombre o partido / departamento.`;
            } catch (error) {
                if (actual === solicitud) estado.textContent = 'No se pudieron cargar las localidades. Volvé a elegir la provincia o recargá el formulario.';
            }
        }
        provincia.addEventListener('change', () => cargar(true));
        busqueda.addEventListener('input', mostrar);
        function modo() {
            const manual = grupo.querySelector('input[name="ubicacion_modo"]:checked').value === 'manual';
            provincia.disabled = manual; localidad.disabled = manual; busqueda.disabled = manual;
            grupo.querySelectorAll('input[name="provincia"],input[name="localidad"]').forEach(campo => {campo.disabled = !manual;});
        }
        grupo.querySelectorAll('input[name="ubicacion_modo"]').forEach(radio => radio.addEventListener('change', modo));
        modo();
        if (provincia.value) cargar(false);
    });
})();
