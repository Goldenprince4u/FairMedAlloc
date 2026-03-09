/**
 * departments.js
 * ==============
 * Utility script for dynamically fetching and populating the "Departments" 
 * dropdown whenever a "Faculty" is selected. Used on Authentication and Profile pages.
 */

/**
 * Queries the API to fetch a list of departments corresponding to the currently selected Faculty ID.
 */
function updateDepartments() {
    const facultySelect = document.getElementById('facultySelect');
    const deptSelect = document.getElementById('deptSelect');
    const facultyId = facultySelect.value;

    // Clear current options and show loading state
    deptSelect.innerHTML = '<option value="">Loading...</option>';

    // Guard clause: reset if nothing is selected
    if (!facultyId) {
        deptSelect.innerHTML = '<option value="">Select Faculty First</option>';
        return;
    }

    // Asynchronously pull JSON department data
    fetch(`api/get_departments.php?faculty_id=${facultyId}`)
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                console.error(data.error);
                deptSelect.innerHTML = '<option value="">Error loading departments</option>';
                return;
            }

            // Re-populate the departments drop-down menu
            deptSelect.innerHTML = '<option value="">Select Department</option>';
            data.forEach(dept => {
                const option = document.createElement('option');
                option.value = dept.id;
                option.textContent = dept.name;
                deptSelect.appendChild(option);
            });

            // State restoration check: Did the user already have a department selected before the page load?
            // (Used commonly in the profile.php file when editing information)
            const currentDept = deptSelect.getAttribute('data-current');
            if (currentDept) {
                deptSelect.value = currentDept;
                // Only set it once on initial load, remove attribute so user's subsequent changes work normally
                deptSelect.removeAttribute('data-current');
            }
        })
        .catch(error => {
            console.error('Error fetching departments:', error);
            deptSelect.innerHTML = '<option value="">Error loading departments</option>';
        });
}

// Ensure the function runs automatically on page load if a faculty is prepopulated.
document.addEventListener('DOMContentLoaded', () => {
    const facultySelect = document.getElementById('facultySelect');
    if (facultySelect && facultySelect.value) {
        updateDepartments();
    }
});
