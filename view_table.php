<?php
/**
 * Allocation Matrix
 * Comprehensive list of all students and their allocation status.
 */
session_start();
require_once 'db_config.php';
require_once 'includes/security_helper.php';

// Auth Guard
if (($_SESSION['role'] ?? '') !== 'admin') { header("Location: login.php"); exit(); }

// Pagination Setup
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 50;
$offset = ($page - 1) * $limit;

// Count Total Records
$count_sql = "SELECT COUNT(*) as total FROM student_profiles p JOIN users u ON p.user_id = u.user_id";
$total_result = $conn->query($count_sql);
$total_rows = $total_result->fetch_assoc()['total'];
$total_pages = ceil($total_rows / $limit);

// Fetch Data with Limit
$query = "
    SELECT 
        p.user_id, p.full_name, p.matric_no, p.faculty, p.department, p.level,
        m.urgency_score, m.condition_category, m.mobility_status,
        h.name as hostel_name, r.room_number,
        u.profile_pic, p.email
    FROM student_profiles p 
    JOIN users u ON p.user_id = u.user_id 
    LEFT JOIN medical_records m ON p.user_id = m.student_id 
    LEFT JOIN allocations a ON p.user_id = a.student_id 
    LEFT JOIN rooms r ON a.room_id = r.room_id 
    LEFT JOIN hostels h ON r.hostel_id = h.hostel_id
    ORDER BY m.urgency_score DESC, p.matric_no ASC 
    LIMIT $limit OFFSET $offset
";
$result = $conn->query($query);

// Fetch Hostels for Manual Allocation
$hostels_result = $conn->query("SELECT hostel_id, name, gender_allowed FROM hostels");
$hostels = [];
while ($h = $hostels_result->fetch_assoc()) {
    $hostels[] = $h;
}

$page_title = "Allocation Matrix | FairMedAlloc";
require_once 'includes/header.php';
?>

<div class="app-shell">
    <?php require_once 'includes/nav.php'; ?>

    <main class="main-content">
        <div class="flex justify-between items-center mb-8">
            <div>
                <h1 class="serif mb-1 text-3xl">Allocation Matrix</h1>
                <p class="text-muted">Master list of student data and allocation decisions.</p>
            </div>
            
            <div class="flex gap-3">
            <div class="flex gap-3">
                <div class="relative">
                    <i class="fa-solid fa-search absolute left-3 top-3 text-muted"></i>
                    <input type="text" id="searchInput" placeholder="Search Matrix..." class="input pl-10 w-[250px]">
                </div>
                <button id="exportBtn" class="btn btn-primary">
                    <i class="fa-solid fa-download"></i> Export CSV
                </button>
            </div>
            </div>
        </div>

        <div class="card p-0 overflow-hidden">
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Student Details</th>
                            <th>Academic Info</th>
                            <th>Medical Priority</th>
                            <th>Allocation Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result && $result->num_rows > 0): ?>
                            <?php while($row = $result->fetch_assoc()): ?>
                                <tr>
                                    <td>
                                        <div class="fw-700"><?php echo htmlspecialchars($row['full_name']); ?></div>
                                        <div class="text-xs text-muted"><?php echo htmlspecialchars($row['matric_no']); ?></div>
                                    </td>
                                    <td>
                                        <div class="text-sm"><?php echo htmlspecialchars($row['faculty']); ?></div>
                                        <div class="text-xs text-muted"><?php echo htmlspecialchars($row['department']); ?> • <?php echo $row['level']; ?>L</div>
                                    </td>
                                    <td>
                                        <?php if(($row['urgency_score'] ?? 0) > 70): ?>
                                            <span class="badge badge-danger">
                                                <i class="fa-solid fa-heart-pulse"></i> HIGH RISK (<?php echo $row['urgency_score']; ?>)
                                            </span>
                                        <?php else: ?>
                                            <span class="badge badge-success">
                                                <i class="fa-solid fa-check"></i> NORMAL
                                            </span>
                                        <?php endif; ?>
                                        
                                        <?php if(!empty($row['condition_category']) && $row['condition_category'] !== 'None'): ?>
                                            <div class="text-xs text-muted mt-1">Condition: <?php echo htmlspecialchars($row['condition_category']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if($row['hostel_name']): ?>
                                            <div class="text-sm fw-700 text-primary">
                                                <?php echo htmlspecialchars($row['hostel_name']); ?>
                                            </div>
                                            <div class="text-xs text-muted">Room <?php echo htmlspecialchars($row['room_number']); ?></div>
                                        <?php else: ?>
                                            <span class="text-xs text-muted" style="font-style: italic;">Pending Allocation</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-right">
                                        <button type="button" 
                                                class="btn btn-sm btn-outline text-primary btn-assign-trigger relative z-10" 
                                                data-id="<?php echo $row['user_id']; ?>" 
                                                data-name="<?php echo htmlspecialchars($row['full_name']); ?>"
                                                title="Manual Allocation">
                                            <i class="fa-solid fa-bed"></i> Assign Room
                                        </button>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="text-center py-8 text-muted">
                                    <i class="fa-regular fa-folder-open mb-2" style="font-size: 2rem; opacity: 0.5;"></i>
                                    <p>No student data found for the current session.</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination -->
            <div class="p-4 flex justify-between items-center text-xs text-muted" style="border-top: 1px solid var(--c-border);">
                <div>
                    Showing <?php echo ($offset + 1); ?>-<?php echo min($offset + $limit, $total_rows); ?> of <?php echo $total_rows; ?> entries
                </div>
                <div class="flex gap-2">
                    <a href="?page=<?php echo max(1, $page - 1); ?>" class="btn btn-sm btn-secondary <?php echo ($page <= 1) ? 'opacity-50 pointer-events-none' : ''; ?>">
                        Previous
                    </a>
                    <button class="btn btn-sm btn-primary"><?php echo $page; ?></button>
                    <a href="?page=<?php echo min($total_pages, $page + 1); ?>" class="btn btn-sm btn-secondary <?php echo ($page >= $total_pages) ? 'opacity-50 pointer-events-none' : ''; ?>">
                        Next
                    </a>
                </div>
            </div>
        </div>
        
    </main>
</div>

<!-- Manual Allocation Modal -->
<div id="assignModal" class="modal-overlay hidden" style="position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 50; display: flex; align-items: center; justify-content: center;">
    <div class="card p-6 w-full max-w-md bg-white shadow-xl rounded-lg">
        <h3 class="text-xl font-bold mb-4">Manual Room Allocation</h3>
        <p class="text-sm text-muted mb-4">Assigning room for: <strong id="assignStudentName">...</strong></p>
        
        <form id="assignForm">
            <input type="hidden" id="assignStudentId" name="student_id">
            
            <div class="mb-4">
                <label class="block text-sm font-bold mb-2">Select Hostel</label>
                <select id="assignHostel" class="input w-full" required>
                    <option value="">-- Choose Hostel --</option>
                    <?php foreach($hostels as $h): ?>
                        <option value="<?php echo $h['hostel_id']; ?>"><?php echo htmlspecialchars($h['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="mb-6">
                <label class="block text-sm font-bold mb-2">Select Room</label>
                <select id="assignRoom" class="input w-full" disabled required>
                    <option value="">-- Select Hostel First --</option>
                </select>
            </div>
            
            <div class="flex justify-end gap-3">
                <button type="button" id="closeModalBtn" class="btn btn-outline">Cancel</button>
                <button type="submit" class="btn btn-primary">Assign Room</button>
            </div>
        </form>
    </div>
</div>

<script>
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
    if(searchInput) {
        searchInput.addEventListener('keyup', filterTable);
    }

    // Hostel Select Change
    const hostelSelect = document.getElementById('assignHostel');
    if(hostelSelect) {
        hostelSelect.addEventListener('change', (e) => fetchRooms(e.target.value));
    }

    // Form Submit
    const assignForm = document.getElementById('assignForm');
    if(assignForm) {
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
    if(!hostelId) {
        roomSelect.innerHTML = '<option value="">-- Select Hostel First --</option>';
        roomSelect.disabled = true;
        return;
    }
    
    roomSelect.innerHTML = '<option>Loading...</option>';
    roomSelect.disabled = true;
    
    fetch(`api/get_rooms.php?hostel_id=${hostelId}`)
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

    fetch('api/manual_assign.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: data
    })
    .then(res => res.json())
    .then(res => {
        if(res.status === 'success') {
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
        if(rowData.length > 0) csv.push(rowData.join(","));
    }

    const csvFile = new Blob([csv.join("\n")], {type: "text/csv"});
    const downloadLink = document.createElement("a");
    downloadLink.download = filename;
    downloadLink.href = window.URL.createObjectURL(csvFile);
    downloadLink.style.display = "none";
    document.body.appendChild(downloadLink);
    downloadLink.click();
}
</script>

</body>
</html>
