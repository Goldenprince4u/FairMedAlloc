/**
 * student_dashboard.js
 * ====================
 * Frontend interactions for the student panel, primarily handling
 * the portal pay-simulator payment process.
 */

document.addEventListener('DOMContentLoaded', () => {
    // Bind the portal payment action to the payment button
    const payBtn = document.getElementById('payBtn');
    if (payBtn) {
        payBtn.addEventListener('click', payFees);
    }
});

/**
 * Triggers the AJAX portal payment simulator request to the backend.
 */
function payFees() {
    const btn  = document.getElementById('payBtn');
    const msg  = document.getElementById('payMsg');
    const csrf = document.querySelector('input[name="csrf_token"]');

    if (!csrf) {
        showPayMsg('Security token missing. Please reload the page.', false, msg);
        return;
    }

    // Swap button into "Processing" state
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-2"></i> Processing...';
    btn.disabled  = true;

    // Dispatch payment request to backend simulation API
    fetch('api/pay_simulation.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ csrf_token: csrf.value })
    })
        .then(res => {
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            return res.json();
        })
        .then(data => {
            if (data.status === 'success') {
                btn.innerHTML = '<i class="fa-solid fa-check mr-2"></i> Payment Confirmed!';
                btn.style.background = 'var(--c-success)';
                btn.style.color      = 'white';
                showPayMsg(data.message || 'Payment successful! Refreshing...', true, msg);
                setTimeout(() => window.location.reload(), 2200);
            } else {
                btn.innerHTML = '<i class="fa-solid fa-credit-card mr-2"></i> Pay on Portal (Simulator) - &#x20A6;50,000';
                btn.disabled  = false;
                showPayMsg(data.message || 'Payment failed. Please try again.', false, msg);
            }
        })
        .catch(() => {
            btn.innerHTML = '<i class="fa-solid fa-credit-card mr-2"></i> Pay on Portal (Simulator) - &#x20A6;50,000';
            btn.disabled  = false;
            showPayMsg('Network error. Check your connection and try again.', false, msg);
        });
}

function showPayMsg(text, success, el) {
    if (!el) return;
    el.innerHTML   = `<span style="color: ${success ? 'var(--c-success)' : 'var(--c-danger)'};">
                        <i class="fa-solid ${success ? 'fa-check-circle' : 'fa-circle-exclamation'} mr-1"></i>${text}
                      </span>`;
    el.classList.remove('hidden');
}
