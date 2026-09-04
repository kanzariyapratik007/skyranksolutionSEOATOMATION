// ============================================================
// SEO 80/20 System - Main JavaScript
// ============================================================

document.addEventListener('DOMContentLoaded', function () {
  // Auto-dismiss alerts after 5 seconds
  document.querySelectorAll('.alert-dismissible').forEach(function (alert) {
    setTimeout(function () {
      const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
      if (bsAlert) bsAlert.close();
    }, 5000);
  });

  // Tooltip initialization
  document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
    new bootstrap.Tooltip(el);
  });
});

// ============================================================
// Utility: Show toast notification
// ============================================================
function showToast(message, type = 'success') {
  const toastContainer = document.getElementById('toastContainer') || createToastContainer();
  const id = 'toast-' + Date.now();
  const icons = { success: 'check-circle', danger: 'exclamation-circle', warning: 'exclamation-triangle', info: 'info-circle' };
  const html = `
    <div id="${id}" class="toast align-items-center text-bg-${type} border-0" role="alert">
      <div class="d-flex">
        <div class="toast-body">
          <i class="fas fa-${icons[type] || 'info-circle'} me-2"></i>${message}
        </div>
        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
      </div>
    </div>`;
  toastContainer.insertAdjacentHTML('beforeend', html);
  const toastEl = document.getElementById(id);
  const toast = new bootstrap.Toast(toastEl, { delay: 4000 });
  toast.show();
  toastEl.addEventListener('hidden.bs.toast', () => toastEl.remove());
}

function createToastContainer() {
  const div = document.createElement('div');
  div.id = 'toastContainer';
  div.className = 'toast-container position-fixed bottom-0 end-0 p-3';
  div.style.zIndex = '9999';
  document.body.appendChild(div);
  return div;
}

// ============================================================
// Utility: Confirm before destructive action
// ============================================================
function confirmAction(message, callback) {
  if (confirm(message)) callback();
}

// ============================================================
// Utility: Format numbers
// ============================================================
function formatNumber(n) {
  return new Intl.NumberFormat().format(n);
}

// ============================================================
// Copy to clipboard
// ============================================================
function copyToClipboard(text) {
  if (navigator.clipboard) {
    navigator.clipboard.writeText(text).then(() => showToast('Copied to clipboard!'));
  } else {
    const el = document.createElement('textarea');
    el.value = text;
    document.body.appendChild(el);
    el.select();
    document.execCommand('copy');
    document.body.removeChild(el);
    showToast('Copied!');
  }
}

// ============================================================
// SEO Score color helper
// ============================================================
function getScoreColor(score) {
  if (score >= 70) return '#198754';
  if (score >= 40) return '#ffc107';
  return '#dc3545';
}

// ============================================================
// Loading button helper
// ============================================================
function setLoading(btn, loading, originalText) {
  if (loading) {
    btn.disabled = true;
    btn.dataset.originalText = btn.innerHTML;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Loading...';
  } else {
    btn.disabled = false;
    btn.innerHTML = originalText || btn.dataset.originalText || 'Submit';
  }
}
