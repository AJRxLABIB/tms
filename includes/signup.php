<?php
/**
 * signup.php — Registration Modal
 * Included by includes/header.php
 * Fixed: proper CSRF token + AJAX registration
 */
?>
<!-- ══ SIGNUP MODAL ══════════════════════════════════════════════════════════ -->
<div id="signupModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9999;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:16px;width:100%;max-width:440px;margin:20px;overflow:hidden;box-shadow:0 24px 60px rgba(0,0,0,.4);">

    <!-- Header -->
    <div style="background:linear-gradient(135deg,#667eea,#764ba2);padding:24px 28px 20px;color:#fff;position:relative;">
      <button onclick="closeModal('signupModal')" style="position:absolute;top:14px;right:16px;background:rgba(255,255,255,.2);border:none;color:#fff;width:28px;height:28px;border-radius:50%;cursor:pointer;font-size:16px;line-height:1;">&times;</button>
      <div style="font-size:20px;font-weight:800;"><i class="fa fa-globe"></i> TMS</div>
      <div style="font-size:15px;font-weight:600;margin-top:6px;opacity:.9;"><i class="fa fa-user-plus"></i> Create Account</div>
    </div>

    <!-- Body -->
    <div style="padding:24px 28px 28px;">
      <div id="signupAlert" style="display:none;border-radius:8px;padding:10px 14px;font-size:13px;margin-bottom:14px;"></div>

      <form id="signupForm">
        <input type="hidden" name="csrf_token" id="signupCsrf" value="<?= htmlspecialchars(tms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="register">

        <div style="margin-bottom:13px;">
          <label style="font-size:11px;font-weight:700;color:#4a5568;text-transform:uppercase;letter-spacing:.5px;display:block;margin-bottom:5px;">Full Name</label>
          <div style="position:relative;">
            <i class="fa fa-user" style="position:absolute;left:11px;top:50%;transform:translateY(-50%);color:#a0aec0;font-size:13px;"></i>
            <input type="text" name="FullName" placeholder="Your full name"
                   style="width:100%;padding:10px 12px 10px 32px;border:2px solid #e2e8f0;border-radius:8px;font-size:13px;outline:none;transition:.2s;"
                   onfocus="this.style.borderColor='#667eea'" onblur="this.style.borderColor='#e2e8f0'"
                   required minlength="3" maxlength="100">
          </div>
        </div>

        <div style="margin-bottom:13px;">
          <label style="font-size:11px;font-weight:700;color:#4a5568;text-transform:uppercase;letter-spacing:.5px;display:block;margin-bottom:5px;">Mobile Number</label>
          <div style="position:relative;">
            <i class="fa fa-phone" style="position:absolute;left:11px;top:50%;transform:translateY(-50%);color:#a0aec0;font-size:13px;"></i>
            <input type="tel" name="MobileNumber" placeholder="+880 1XXX-XXXXXX"
                   style="width:100%;padding:10px 12px 10px 32px;border:2px solid #e2e8f0;border-radius:8px;font-size:13px;outline:none;transition:.2s;"
                   onfocus="this.style.borderColor='#667eea'" onblur="this.style.borderColor='#e2e8f0'"
                   required maxlength="15">
          </div>
        </div>

        <div style="margin-bottom:13px;">
          <label style="font-size:11px;font-weight:700;color:#4a5568;text-transform:uppercase;letter-spacing:.5px;display:block;margin-bottom:5px;">Email Address</label>
          <div style="position:relative;">
            <i class="fa fa-envelope" style="position:absolute;left:11px;top:50%;transform:translateY(-50%);color:#a0aec0;font-size:13px;"></i>
            <input type="email" name="EmailId" placeholder="you@example.com"
                   style="width:100%;padding:10px 12px 10px 32px;border:2px solid #e2e8f0;border-radius:8px;font-size:13px;outline:none;transition:.2s;"
                   onfocus="this.style.borderColor='#667eea'" onblur="this.style.borderColor='#e2e8f0'"
                   required maxlength="100">
          </div>
        </div>

        <div style="margin-bottom:13px;">
          <label style="font-size:11px;font-weight:700;color:#4a5568;text-transform:uppercase;letter-spacing:.5px;display:block;margin-bottom:5px;">Password</label>
          <div style="position:relative;">
            <!-- ACCESSIBILITY FIX (Round 69, WCAG 2.5.8 target size): this
                 eye-toggle button (and its twin below, on Confirm Password)
                 used to have padding:0 and no explicit box, so its
                 clickable area was only the ~13px icon glyph itself --
                 well under the 24px minimum on both axes. An explicit
                 24x24 flex-centered box fixes this without moving the
                 icon's visible position (right nudged from 10px to 4px to
                 compensate for the wider box). -->
            <i class="fa fa-lock" style="position:absolute;left:11px;top:50%;transform:translateY(-50%);color:#a0aec0;font-size:13px;"></i>
            <input type="password" name="Password" id="suPass" placeholder="Min. 6 characters"
                   style="width:100%;padding:10px 32px 10px 32px;border:2px solid #e2e8f0;border-radius:8px;font-size:13px;outline:none;transition:.2s;"
                   onfocus="this.style.borderColor='#667eea'" onblur="this.style.borderColor='#e2e8f0'"
                   required minlength="6" maxlength="100">
            <button type="button" onclick="suToggle('suPass',this)" style="position:absolute;right:4px;top:50%;transform:translateY(-50%);background:none;border:none;color:#a0aec0;cursor:pointer;padding:0;font-size:13px;width:24px;height:24px;display:flex;align-items:center;justify-content:center;"><i class="fa fa-eye"></i></button>
          </div>
        </div>

        <div style="margin-bottom:18px;">
          <label style="font-size:11px;font-weight:700;color:#4a5568;text-transform:uppercase;letter-spacing:.5px;display:block;margin-bottom:5px;">Confirm Password</label>
          <div style="position:relative;">
            <i class="fa fa-lock" style="position:absolute;left:11px;top:50%;transform:translateY(-50%);color:#a0aec0;font-size:13px;"></i>
            <input type="password" name="CPassword" id="suCPass" placeholder="Repeat your password"
                   style="width:100%;padding:10px 32px 10px 32px;border:2px solid #e2e8f0;border-radius:8px;font-size:13px;outline:none;transition:.2s;"
                   onfocus="this.style.borderColor='#667eea'" onblur="this.style.borderColor='#e2e8f0'"
                   required minlength="6" maxlength="100">
            <button type="button" onclick="suToggle('suCPass',this)" style="position:absolute;right:4px;top:50%;transform:translateY(-50%);background:none;border:none;color:#a0aec0;cursor:pointer;padding:0;font-size:13px;width:24px;height:24px;display:flex;align-items:center;justify-content:center;"><i class="fa fa-eye"></i></button>
          </div>
        </div>

        <button type="submit" id="signupBtn"
                style="width:100%;background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;border:none;border-radius:8px;height:44px;font-size:14px;font-weight:700;cursor:pointer;transition:.2s;">
          <i class="fa fa-user-plus"></i> Create Account
        </button>
      </form>

      <div style="text-align:center;margin-top:16px;font-size:13px;color:#718096;">
        Already have an account?
        <a href="javascript:void(0)" onclick="closeModal('signupModal');openModal('signinModal')"
           style="color:#667eea;font-weight:700;text-decoration:none;">Sign In</a>
      </div>
    </div>

  </div>
</div>

<script>
// Toggle password visibility
function suToggle(id, btn) {
  var f = document.getElementById(id);
  var i = btn.querySelector('i');
  if (f.type === 'password') { f.type = 'text';     i.className = 'fa fa-eye-slash'; }
  else                        { f.type = 'password'; i.className = 'fa fa-eye'; }
}

// Show alert inside modal
function suAlert(msg, type) {
  var el = document.getElementById('signupAlert');
  el.style.display    = 'block';
  el.style.background = type === 'success' ? '#f0fff4' : '#fff5f5';
  el.style.color      = type === 'success' ? '#276749' : '#c53030';
  el.innerHTML = (type === 'success' ? '✅ ' : '❌ ') + msg;
}

document.getElementById('signupForm').addEventListener('submit', function(e) {
  e.preventDefault();

  var pass  = document.getElementById('suPass').value;
  var cpass = document.getElementById('suCPass').value;
  if (pass !== cpass) { suAlert('Passwords do not match.', 'error'); return; }

  var btn = document.getElementById('signupBtn');
  btn.disabled   = true;
  btn.innerHTML  = '<i class="fa fa-spinner fa-spin"></i> Creating account…';

  var data = new FormData(this);

  fetch('user-register-ajax.php', { method: 'POST', body: data })
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        suAlert('Account created! You can now sign in.', 'success');
        this.reset();
        setTimeout(function() {
          closeModal('signupModal');
          openModal('signinModal');
        }, 1800);
      } else {
        suAlert(res.message || 'Registration failed. Please try again.', 'error');
      }
    })
    .catch(function() {
      suAlert('Network error. Please try again.', 'error');
    })
    .finally(function() {
      btn.disabled  = false;
      btn.innerHTML = '<i class="fa fa-user-plus"></i> Create Account';
    });
});
</script>