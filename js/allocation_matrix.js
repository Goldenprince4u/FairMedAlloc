document.addEventListener('DOMContentLoaded', () => {

    // --- 1. Event Delegation for dynamic buttons ---
    document.body.addEventListener('click', (e) => {

        // Open Assign Modal
        const assignBtn = e.target.closest('.btn-assign-trigger');
        if (assignBtn) {
            const id = assignBtn.dataset.id;
            const name = assignBtn.dataset.name;
            openAssignModal(id, name);
        }

        // Close Modal via Backdrop or Cancel Button
        if (e.target.id === 'assignModal' || e.target.id === 'closeModalBtn') {
            closeAssignModal();
        }

        // Export CSV
        if (e.target.closest('#exportBtn')) {
            exportTableToCSV('allocation_matrix.csv');
        }
    });

    // --- 2. Input Listeners ---

    // Search Filter
    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.addEventListener('keyup', filterTable);
    }

    // Hostel Select Change
    const hostelSelect = document.getElementById('assignHostel');
    if (hostelSelect) {
        hostelSelect.addEventListener('change', (e) => fetchRooms(e.target.value));
    }

    // Form Submit
    const assignForm = document.getElementById('assignForm');
    if (assignForm) {
        assignForm.addEventListener('submit', (e) => {
            e.preventDefault();
            submitAssignment();
        });
    }
});

// --- Logic Functions ---

function openAssignModal(id, name) {
    document.getElementById('assignStudentId').value = id;
    document.getElementById('assignStudentName').textContent = name;
    document.getElementById('assignModal').classList.remove('hidden');
}

function closeAssignModal() {
    document.getElementById('assignModal').classList.add('hidden');
}

function fetchRooms(hostelId) {
    const roomSelect = document.getElementById('assignRoom');
    if (!hostelId) {
        roomSelect.innerHTML = '<option value="">-- Select Hostel First --</option>';
        roomSelect.disabled = true;
        return;
    }

    roomSelect.innerHTML = '<option>Loading...</option>';
    roomSelect.disabled = true;

    fetch(`api/admin_api.php?action=get_rooms&hostel_id=${hostelId}`)
        .then(res => res.json())
        .then(data => {
            roomSelect.innerHTML = '<option value="">-- Select Room --</option>';
            data.forEach(room => {
                roomSelect.innerHTML += `<option value="${room.room_id}">Room ${room.room_number}</option>`;
            });
            roomSelect.disabled = false;
        })
        .catch(err => {
            alert('Failed to load rooms');
            roomSelect.disabled = true;
        });
}

function submitAssignment() {
    const form = document.getElementById('assignForm');
    const formData = new FormData(form);
    const data = new URLSearchParams(formData);

    fetch('api/admin_api.php?action=manual_assign', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: data
    })
        .then(res => res.json())
        .then(res => {
            if (res.status === 'success') {
                alert('Allocation successful!');
                location.reload();
            } else {
                alert(res.message || 'Allocation failed');
            }
        })
        .catch(err => alert('Network error'));
}

function filterTable() {
    const input = document.getElementById("searchInput");
    const filter = input.value.toUpperCase();
    const rows = document.querySelectorAll("table tbody tr");

    rows.forEach(row => {
        const text = row.textContent || row.innerText;
        row.style.display = text.toUpperCase().includes(filter) ? "" : "none";
    });
}

function exportTableToCSV(filename) {
    const csv = [];
    const rows = document.querySelectorAll("table tr");

    for (let i = 0; i < rows.length; i++) {
        if (rows[i].style.display === 'none') continue; // Skip filtered rows

        const cols = rows[i].querySelectorAll("td, th");
        let rowData = [];
        // Skip last column (Actions)
        const colCount = cols.length - 1;

        for (let j = 0; j < colCount; j++) {
            let data = cols[j].innerText.replace(/(\r\n|\n|\r)/gm, " ").trim();
            data = data.replace(/"/g, '""');
            rowData.push(`"${data}"`);
        }
        if (rowData.length > 0) csv.push(rowData.join(","));
    }

    const csvFile = new Blob([csv.join("\n")], { type: "text/csv" });
    const downloadLink = document.createElement("a");
    downloadLink.download = filename;
    downloadLink.href = window.URL.createObjectURL(csvFile);
    downloadLink.style.display = "none";
    document.body.appendChild(downloadLink);
    downloadLink.click();
}
