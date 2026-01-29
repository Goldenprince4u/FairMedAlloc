document.addEventListener('DOMContentLoaded', () => {
    const payBtn = document.getElementById('payBtn');
    if (payBtn) {
        payBtn.addEventListener('click', payFees);
    }
});

function payFees() {
    const btn = document.getElementById('payBtn');
    const msg = document.getElementById('payMsg');
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-2"></i> Processing...';
    btn.disabled = true;

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
                btn.innerHTML = '<i class="fa-solid fa-check mr-2"></i> Paid Successfully';
                btn.classList.remove('btn-primary');
                btn.classList.add('bg-green-600', 'text-white');

                // Show detailed allocation message
                msg.innerHTML = `<span class="text-success">${data.message}</span>`;
                msg.classList.remove('hidden');

                setTimeout(() => window.location.reload(), 2000);
            } else {
                btn.innerHTML = 'Try Again';
                btn.disabled = false;
                msg.innerHTML = `<span class="text-danger">${data.message}</span>`;
                msg.classList.remove('hidden');
            }
        });
}
