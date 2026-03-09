/**
 * student_dashboard.js
 * ====================
 * Frontend interactions for the student panel, primarily handling
 * the simulated school fee payment processes.
 */

document.addEventListener('DOMContentLoaded', () => {
    // Bind the Pay Fees action to the payment button
    const payBtn = document.getElementById('payBtn');
    if (payBtn) {
        payBtn.addEventListener('click', payFees);
    }
});

/**
 * Triggers the AJAX payment simulation to the backend.
 * Upon successful payment, it alerts the backend to instantly attempt to allocate a room.
 */
function payFees() {
    const btn = document.getElementById('payBtn');
    const msg = document.getElementById('payMsg');

    // Swap button into "Processing/Spinning" state
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-2"></i> Processing...';
    btn.disabled = true;

    // Dispatch payment request to backend simulation API
    fetch('api/pay_simulation.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            csrf_token: document.querySelector('input[name="csrf_token"]').value
        })
    })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                // UI Feedback on Success
                btn.innerHTML = '<i class="fa-solid fa-check mr-2"></i> Paid Successfully';
                btn.classList.remove('btn-primary');
                // Make the button green to signify success
                btn.classList.add('bg-green-600', 'text-white');

                // Display detailed allocation message from the server backend
                msg.innerHTML = `<span class="text-success">${data.message}</span>`;
                msg.classList.remove('hidden');

                // Reload page after 2 seconds so the new allocation status renders in PHP
                setTimeout(() => window.location.reload(), 2000);
            } else {
                // Reset button on failure and display error to user
                btn.innerHTML = 'Try Again';
                btn.disabled = false;
                msg.innerHTML = `<span class="text-danger">${data.message}</span>`;
                msg.classList.remove('hidden');
            }
        });
}
