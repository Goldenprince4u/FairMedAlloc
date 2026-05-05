/**
 * allocation_matrix.js
 * ====================
 * Handles dynamic interactions on the administrative "Allocation Matrix" table.
 * Manages modal popups, async room fetching, CSV exporting, and searching.
 */

document.addEventListener('DOMContentLoaded', () => {
    document.body.addEventListener('click', (e) => {
        const assignBtn = e.target.closest('.btn-assign-trigger');
        if (assignBtn) {
            const id = assignBtn.dataset.id;
            const name = assignBtn.dataset.name;
            openAssignModal(id, name);
        }

        if (
            e.target.id === 'assignModal' ||
            e.target.id === 'closeModalIconBtn' ||
            e.target.id === 'closeModalCancelBtn' ||
            e.target.closest('#closeModalIconBtn') ||
            e.target.closest('#closeModalCancelBtn')
        ) {
            closeAssignModal();
        }
    });

    // Search and export are now handled completely server-side via PHP forms.

    const hostelSelect = document.getElementById('assignHostel');
    if (hostelSelect) {
        hostelSelect.addEventListener('change', (e) => fetchRooms(e.target.value));
    }

    const assignForm = document.getElementById('assignForm');
    if (assignForm) {
        assignForm.addEventListener('submit', (e) => {
            e.preventDefault();
            submitAssignment();
        });
    }
});

function openAssignModal(id, name) {
    document.getElementById('assignStudentId').value = id;
    document.getElementById('assignStudentName').textContent = name;
    document.getElementById('assignHostel').value = '';

    const roomSelect = document.getElementById('assignRoom');
    roomSelect.innerHTML = '<option value="">-- Select Hostel First --</option>';
    roomSelect.disabled = true;

    const infoEl = document.getElementById('roomAvailInfo');
    if (infoEl) {
        infoEl.style.display = 'none';
        infoEl.textContent = '';
    }

    document.getElementById('assignModal').style.display = 'flex';
}

function closeAssignModal() {
    document.getElementById('assignModal').style.display = 'none';
    document.getElementById('assignForm').reset();

    const roomSelect = document.getElementById('assignRoom');
    roomSelect.innerHTML = '<option value="">-- Select Hostel First --</option>';
    roomSelect.disabled = true;

    const infoEl = document.getElementById('roomAvailInfo');
    if (infoEl) {
        infoEl.style.display = 'none';
        infoEl.textContent = '';
    }
}

function fetchRooms(hostelId) {
    const roomSelect = document.getElementById('assignRoom');
    const infoEl = document.getElementById('roomAvailInfo');

    if (!hostelId) {
        roomSelect.innerHTML = '<option value="">-- Select Hostel First --</option>';
        roomSelect.disabled = true;
        if (infoEl) {
            infoEl.style.display = 'none';
            infoEl.textContent = '';
        }
        return;
    }

    roomSelect.innerHTML = '<option value="">Loading rooms...</option>';
    roomSelect.disabled = true;

    fetch(`api/admin_api.php?action=get_rooms&hostel_id=${hostelId}`)
        .then((res) => res.json())
        .then((data) => {
            if (!Array.isArray(data) || data.length === 0) {
                roomSelect.innerHTML = '<option value="">No available rooms</option>';
                if (infoEl) {
                    infoEl.textContent = 'This hostel is fully occupied.';
                    infoEl.style.display = 'block';
                    infoEl.style.color = 'var(--c-danger)';
                }
                return;
            }

            roomSelect.innerHTML = '<option value="">-- Select Room --</option>';
            data.forEach((room) => {
                const floorLabel = Number(room.floor_level) === 0 ? 'Ground' : `Floor ${room.floor_level}`;
                const free = room.available ?? (room.capacity - room.occupied_count);
                roomSelect.innerHTML += `<option value="${room.room_id}">Room ${room.room_number} - ${floorLabel} (${free} bed${free !== 1 ? 's' : ''} free)</option>`;
            });

            roomSelect.disabled = false;
            if (infoEl) {
                infoEl.textContent = `${data.length} room(s) available in this hostel.`;
                infoEl.style.display = 'block';
                infoEl.style.color = 'var(--c-text-muted)';
            }
        })
        .catch(() => {
            showToast('Failed to load rooms. Check your connection.', 'danger');
            roomSelect.disabled = true;
            if (infoEl) {
                infoEl.style.display = 'none';
                infoEl.textContent = '';
            }
        });
}

function submitAssignment() {
    const form = document.getElementById('assignForm');
    const btn = form.querySelector('button[type="submit"]');
    const formData = new FormData(form);

    if (!formData.get('room_id')) {
        showToast('Please choose a room before assigning.', 'warning');
        return;
    }

    btn.disabled = true;
    btn.textContent = 'Assigning...';

    fetch('api/admin_api.php?action=manual_assign', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams(formData)
    })
        .then((res) => res.json())
        .then((res) => {
            if (res.status === 'success') {
                showToast('Room assigned successfully!', 'success');
                closeAssignModal();
                setTimeout(() => location.reload(), 1200);
                return;
            }

            showToast(res.message || 'Assignment failed. Please try again.', 'danger');
            btn.disabled = false;
            btn.textContent = 'Assign Room';
        })
        .catch(() => {
            showToast('Network error. Please check your connection.', 'danger');
            btn.disabled = false;
            btn.textContent = 'Assign Room';
        });
}

    // Client-side filtering and exporting have been removed in favor of robust server-side processing.

function showToast(message, type = 'info') {
    const existing = document.getElementById('fm-toast');
    if (existing) {
        existing.remove();
    }

    const colours = {
        success: { bg: 'var(--c-success)', icon: 'fa-check-circle' },
        danger: { bg: 'var(--c-danger)', icon: 'fa-circle-exclamation' },
        info: { bg: 'var(--c-info)', icon: 'fa-circle-info' },
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

    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transition = 'opacity 0.4s';
    }, 3000);
    setTimeout(() => toast.remove(), 3500);
}
