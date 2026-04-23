/**
 * allocation_matrix.js
 * ====================
 * Handles dynamic interactions on the administrative "Allocation Matrix" table.
 * Manages modal popups, async room fetching, CSV exporting, and searching.
 */

document.addEventListener('DOMContentLoaded', () => {

    // --- 1. Event Delegation for dynamic buttons ---
    document.body.addEventListener('click', (e) => {

        // Open Assign Modal when "Assign" button is clicked
        const assignBtn = e.target.closest('.btn-assign-trigger');
        if (assignBtn) {
            const id   = assignBtn.dataset.id;
            const name = assignBtn.dataset.name;
            openAssignModal(id, name);
        }

        // Close Modal via clicking the grey Backdrop or the Cancel Button
        if (e.target.id === 'assignModal' || e.target.id === 'closeModalBtn') {
            closeAssignModal();
        }

        // Export table data to CSV file
        if (e.target.closest('#exportBtn')) {
            exportTableToCSV('allocation_matrix.csv');
        }
    });

    // --- 2. Input Listeners ---

    // Search Filter: Type to instantly filter table rows
    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.addEventListener('input', filterTable);
        // Persist search term across pagination (store in sessionStorage)
        const saved = sessionStorage.getItem('matrixSearch');
        if (saved) {
            searchInput.value = saved;
            filterTable();
        }
        searchInput.addEventListener('input', () => {
            sessionStorage.setItem('matrixSearch', searchInput.value);
        });
    }

    // Hostel Select Change: fetch rooms for that hostel
    const hostelSelect = document.getElementById('assignHostel');
    if (hostelSelect) {
        hostelSelect.addEventListener('change', (e) => fetchRooms(e.target.value));
    }

    // Form Submit: AJAX manual allocation
    const assignForm = document.getElementById('assignForm');
    if (assignForm) {
        assignForm.addEventListener('submit', (e) => {
            e.preventDefault();
            submitAssignment();
        });
    }
});

/* ---- Logic Functions ---- */

function openAssignModal(id, name) {
    document.getElementById('assignStudentId').value        = id;
    document.getElementById('assignStudentName').textContent = name;
    // Reset room select
    const roomSelect = document.getElementById('assignRoom');
    roomSelect.innerHTML = '<option value="">-- Select Hostel First --</option>';
    roomSelect.disabled  = true;
    const infoEl = document.getElementById('roomAvailInfo');
    if (infoEl) infoEl.style.display = 'none';
    // Show modal using flexbox (matches the inline display:none → flex pattern)
    const modal = document.getElementById('assignModal');
    modal.style.display = 'flex';
}

function closeAssignModal() {
    document.getElementById('assignModal').style.display = 'none';
    // Reset form state
    document.getElementById('assignForm').reset();
}

/**
 * Fetches available rooms for a given hostel and populates the room dropdown.
 * Now shows "(X beds free)" next to each room number.
 */
function fetchRooms(hostelId) {
    const roomSelect = document.getElementById('assignRoom');
    const infoEl     = document.getElementById('roomAvailInfo');

    if (!hostelId) {
        roomSelect.innerHTML = '<option value="">-- Select Hostel First --</option>';
        roomSelect.disabled  = true;
        if (infoEl) infoEl.style.display = 'none';
        return;
    }

    roomSelect.innerHTML = '<option>Loading rooms…</option>';
    roomSelect.disabled  = true;

    fetch(`api/admin_api.php?action=get_rooms&hostel_id=${hostelId}`)
        .then(res => res.json())
        .then(data => {
            if (!data.length) {
                roomSelect.innerHTML = '<option value="">No available rooms</option>';
                if (infoEl) {
                    infoEl.textContent   = 'This hostel is fully occupied.';
                    infoEl.style.display = 'block';
                    infoEl.style.color   = 'var(--c-danger)';
                }
                return;
            }
            roomSelect.innerHTML = '<option value="">-- Select Room --</option>';
            data.forEach(room => {
                const floorLabel = room.floor_level === 0 ? 'Ground' : `Floor ${room.floor_level}`;
                const free       = room.available ?? (room.capacity - room.occupied_count);
                roomSelect.innerHTML += `<option value="${room.room_id}">Room ${room.room_number} — ${floorLabel} (${free} bed${free !== 1 ? 's' : ''} free)</option>`;
            });
            roomSelect.disabled = false;
            if (infoEl) {
                infoEl.textContent   = `${data.length} room(s) available in this hostel.`;
                infoEl.style.display = 'block';
                infoEl.style.color   = 'var(--c-text-muted)';
            }
        })
        .catch(() => {
            showToast('Failed to load rooms. Check your connection.', 'danger');
            roomSelect.disabled = true;
        });
}

/**
 * Sends a manual allocation override request via AJAX.
 */
function submitAssignment() {
    const form    = document.getElementById('assignForm');
    const btn     = form.querySelector('button[type="submit"]');
    const formData = new FormData(form);

    btn.disabled     = true;
    btn.textContent  = 'Assigning…';

    // Append CSRF token from the hidden input in the page
    const csrfInput = document.querySelector('input[name="csrf_token"]');
    if (csrfInput) formData.append('csrf_token', csrfInput.value);

    fetch('api/admin_api.php?action=manual_assign', {
        method:  'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body:    new URLSearchParams(formData)
    })
        .then(res => res.json())
        .then(res => {
            if (res.status === 'success') {
                showToast('Room assigned successfully!', 'success');
                closeAssignModal();
                setTimeout(() => location.reload(), 1200);
            } else {
                showToast(res.message || 'Assignment failed. Please try again.', 'danger');
                btn.disabled    = false;
                btn.textContent = 'Assign Room';
            }
        })
        .catch(() => {
            showToast('Network error. Please check your connection.', 'danger');
            btn.disabled    = false;
            btn.textContent = 'Assign Room';
        });
}

/**
 * Client-side table filtering by name, matric, faculty, or hostel name.
 */
function filterTable() {
    const filter = document.getElementById('searchInput').value.toUpperCase().trim();
    const rows   = document.querySelectorAll('table tbody tr');

    rows.forEach(row => {
        const text = row.textContent || row.innerText;
        row.style.display = text.toUpperCase().includes(filter) ? '' : 'none';
    });
}

/**
 * Downloads visible table rows as a CSV file.
 */
function exportTableToCSV(filename) {
    const csv  = [];
    const rows = document.querySelectorAll('table tr');

    for (const row of rows) {
        if (row.style.display === 'none') continue;
        const cols    = row.querySelectorAll('td, th');
        const rowData = [];
        // Skip the last "Actions" column
        for (let j = 0; j < cols.length - 1; j++) {
            let cell = cols[j].innerText.replace(/(\r\n|\n|\r)/gm, ' ').trim();
            cell     = cell.replace(/"/g, '""');
            rowData.push(`"${cell}"`);
        }
        if (rowData.length > 0) csv.push(rowData.join(','));
    }

    const blob = new Blob([csv.join('\n')], { type: 'text/csv' });
    const a    = document.createElement('a');
    a.download = filename;
    a.href     = URL.createObjectURL(blob);
    a.style.display = 'none';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
}

/**
 * Shows a non-blocking toast notification (replaces alert()).
 * @param {string} message
 * @param {'success'|'danger'|'info'|'warning'} type
 */
function showToast(message, type = 'info') {
    // Remove existing toast
    const existing = document.getElementById('fm-toast');
    if (existing) existing.remove();

    const colours = {
        success: { bg: 'var(--c-success)', icon: 'fa-check-circle' },
        danger:  { bg: 'var(--c-danger)',  icon: 'fa-circle-exclamation' },
        info:    { bg: 'var(--c-info)',    icon: 'fa-circle-info' },
        warning: { bg: 'var(--c-warning)', icon: 'fa-triangle-exclamation' }
    };
    const c = colours[type] || colours.info;

    const toast = document.createElement('div');
    toast.id = 'fm-toast';
    toast.style.cssText = `
        position: fixed; bottom: 2rem; right: 2rem; z-index: 9999;
        background: ${c.bg}; color: white;
        padding: 1rem 1.5rem; border-radius: 12px;
        display: flex; align-items: center; gap: 0.75rem;
        font-family: var(--font-body); font-weight: 600; font-size: 0.9rem;
        box-shadow: 0 8px 32px rgba(0,0,0,0.2);
        animation: fadeInUp 0.4s ease-out;
        max-width: 360px;
    `;
    toast.innerHTML = `<i class="fa-solid ${c.icon}" style="font-size:1.1rem;"></i> <span>${message}</span>`;
    document.body.appendChild(toast);

    setTimeout(() => { toast.style.opacity = '0'; toast.style.transition = 'opacity 0.4s'; }, 3000);
    setTimeout(() => toast.remove(), 3500);
}
