const departments = {
    "Faculty of Computing and Digital Technologies": ["Computer Science", "Information Technology", "Cybersecurity"],
    "Natural Sciences": ["Biochemistry", "Industrial Mathematics", "Microbiology", "Physics", "Chemistry"],
    "Basic Medical Sciences": ["Nursing Science", "Physiology", "Anatomy", "Medical Laboratory Science", "Biochemistry"],
    "Management Sciences": ["Accounting", "Business Administration", "Economics", "Transport Management"],
    "Engineering": ["Civil Engineering", "Mechanical Engineering", "Electrical Engineering"],
    "Humanities": ["English", "History", "Theatre Arts"],
    "Law": ["Law"]
};

function updateDepartments() {
    const faculty = document.getElementById("facultySelect").value;
    const deptSelect = document.getElementById("deptSelect");

    // Get current selection if relevant (passed via data attribute or global)
    // For simplicity, we just reset or try to match if available
    const currentDept = deptSelect.getAttribute('data-current') || "";

    deptSelect.innerHTML = '<option value="">Select Department</option>';

    if (faculty && departments[faculty]) {
        departments[faculty].forEach(dept => {
            const option = document.createElement("option");
            option.value = dept;
            option.text = dept;
            if (dept === currentDept) {
                option.selected = true;
            }
            deptSelect.appendChild(option);
        });
    }
}

document.addEventListener('DOMContentLoaded', function () {
    updateDepartments();
});
