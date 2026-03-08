async function updateDepartments() {
    const facultySelect = document.getElementById("facultySelect");
    const deptSelect = document.getElementById("deptSelect");
    
    const facultyId = facultySelect.value;
    const currentDeptId = deptSelect.getAttribute('data-current') || "";

    deptSelect.innerHTML = '<option value="">Select Department</option>';

    if (!facultyId) return;

    try {
        const response = await fetch(`api/get_departments.php?faculty_id=${facultyId}`);
        const departments = await response.json();

        if (Array.isArray(departments)) {
            departments.forEach(dept => {
                const option = document.createElement("option");
                option.value = dept.id;
                option.text = dept.name;
                
                // If editing profile, reselect their current department
                if (String(dept.id) === String(currentDeptId)) {
                    option.selected = true;
                }
                
                deptSelect.appendChild(option);
            });
        }
    } catch (error) {
        console.error("Error fetching departments:", error);
    }
}

document.addEventListener('DOMContentLoaded', function () {
    updateDepartments();
});
