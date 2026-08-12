// PoliBA Client-Side Interactions

document.addEventListener('DOMContentLoaded', function() {
    // 1. Sidebar Panel Navigation Toggle
    const sidebarToggle = document.getElementById('sidebar-toggle');
    const sidebar = document.getElementById('poliba-sidebar');
    const sidebarClose = document.getElementById('sidebar-close');
    
    // Create Backdrop Element dynamically if it doesn't exist
    let backdrop = document.getElementById('sidebar-backdrop');
    if (!backdrop && sidebar) {
        backdrop = document.createElement('div');
        backdrop.id = 'sidebar-backdrop';
        backdrop.className = 'sidebar-backdrop';
        document.body.appendChild(backdrop);
    }
    
    function openSidebar() {
        if (sidebar && backdrop) {
            sidebar.classList.add('open');
            backdrop.classList.add('show');
            document.body.style.overflow = 'hidden'; // Disable page scrolling
        }
    }
    
    function closeSidebar() {
        if (sidebar && backdrop) {
            sidebar.classList.remove('open');
            backdrop.classList.remove('show');
            document.body.style.overflow = ''; // Restore scrolling
        }
    }
    
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', function(e) {
            e.preventDefault();
            openSidebar();
        });
    }
    
    if (sidebarClose) {
        sidebarClose.addEventListener('click', closeSidebar);
    }
    
    if (backdrop) {
        backdrop.addEventListener('click', closeSidebar);
    }
    
    // Esc Key closes sidebar
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeSidebar();
        }
    });

    // 2. MapLibre GL JS Map Initialization (Ficha de Polideportivo)
    const mapContainer = document.getElementById('poliba-map');
    if (mapContainer) {
        // Read lat,lng and name from data attributes
        const lat = parseFloat(mapContainer.getAttribute('data-lat')) || -34.603722; // default Buenos Aires Obelisco
        const lng = parseFloat(mapContainer.getAttribute('data-lng')) || -58.381592;
        const name = mapContainer.getAttribute('data-name') || 'Polideportivo';
        const address = mapContainer.getAttribute('data-address') || '';

        try {
            // Initialize MapLibre GL map
            const map = new maplibregl.Map({
                container: 'poliba-map',
                style: 'https://tiles.openfreemap.org/styles/liberty', // OpenFreeMap Liberty style
                center: [lng, lat],
                zoom: 15
            });

            // Add navigation controls (zoom, compass)
            map.addControl(new maplibregl.NavigationControl(), 'top-right');

            // Add marker
            const marker = new maplibregl.Marker({ color: '#132644' }) // Dark blue theme marker
                .setLngLat([lng, lat])
                .addTo(map);

            // Add Popup
            const popup = new maplibregl.Popup({ offset: 25 })
                .setHTML(`
                    <div style="font-family: inherit; padding: 5px;">
                        <h6 style="font-weight: 800; color: #132644; margin-bottom: 3px;">${name}</h6>
                        <p style="font-size: 12px; margin: 0; color: #64748b;">${address}</p>
                    </div>
                `);
            marker.setPopup(popup);
            
            // Open popup by default
            popup.addTo(map);
            
        } catch (error) {
            console.error('Error initializing MapLibre Map:', error);
            mapContainer.innerHTML = `
                <div class="d-flex align-items-center justify-content-center h-100 border rounded bg-light text-danger p-3">
                    <div>
                        <i class="bi bi-exclamation-triangle-fill fs-2 mb-2"></i>
                        <p class="m-0">No se pudo cargar el mapa interactivo. Detalle: ${name} (${lat}, ${lng})</p>
                    </div>
                </div>
            `;
        }
    }
});
