<?php
/**
 * Poza de profil a membrului (pluginul `membri`, din vertij af8ab87; agățarea `cont.profil`): poza sau inițialele, cu un clic
 * pe ele se alege o imagine nouă, iar formularul pleacă singur (fără JavaScript, butonul „Încarcă”). „Șterge poza” o scoate.
 *
 * @var array<string, mixed> $customer
 */
$customer = is_array($customer ?? null) ? $customer : [];
$esc = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES);
$avatarUrl = trim((string) ($customer['avatar_url'] ?? ''));
$avatarInitials = trim(mb_strtoupper(mb_substr((string) ($customer['first_name'] ?? ''), 0, 1))
    . mb_strtoupper(mb_substr((string) ($customer['last_name'] ?? ''), 0, 1)));
if ($avatarInitials === '') {
    $avatarInitials = 'M';
}
?>
<style>
.acc-avatar-block{display:flex;align-items:center;gap:16px;margin:0 0 18px;}
.acc-avatar-form{flex:0 0 auto;margin:0;line-height:0;}
.acc-avatar{position:relative;width:72px;height:72px;border-radius:50%;background:#f4ecf7;border:2px solid #e5d9ec;color:#6f2b98;
  display:flex;align-items:center;justify-content:center;font-size:26px;font-weight:700;letter-spacing:.02em;
  cursor:pointer;transition:transform .18s ease,box-shadow .18s ease;}
.acc-avatar:hover,.acc-avatar:focus-within{transform:scale(1.04);box-shadow:0 0 0 4px rgba(111,43,152,.12);}
.acc-avatar img{width:100%;height:100%;border-radius:50%;object-fit:cover;display:block;}
.acc-avatar-initials{line-height:1;}
.acc-avatar-input{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0 0 0 0);border:0;}
.acc-avatar-sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;}
.acc-avatar-edit{position:absolute;right:-2px;bottom:-2px;width:26px;height:26px;border-radius:50%;background:#fff;
  border:2px solid #e5d9ec;display:flex;align-items:center;justify-content:center;
  box-shadow:0 4px 10px rgba(31,41,55,.22);opacity:0;transform:scale(.82);transition:opacity .18s ease,transform .18s ease;}
.acc-avatar-edit svg{width:14px;height:14px;fill:none;stroke:#6f2b98;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;}
.acc-avatar.has-no-photo .acc-avatar-edit,.acc-avatar:hover .acc-avatar-edit,.acc-avatar:focus-within .acc-avatar-edit{opacity:1;transform:scale(1);}
.acc-avatar-remove{margin:0;}
.acc-avatar-remove button{background:none;border:0;padding:0;color:#a11d33;font-size:13px;cursor:pointer;text-decoration:underline;}
@media (prefers-reduced-motion: reduce){.acc-avatar,.acc-avatar-edit{transition:none;}}
</style>
<div class="acc-avatar-block">
    <form class="acc-avatar-form" method="post" action="/contul-meu/poza" enctype="multipart/form-data" data-avatar-form>
        <?= \App\Support\AdminCsrf::camp() . "\n" ?>
        <label class="acc-avatar <?= $avatarUrl === '' ? 'has-no-photo' : '' ?>" for="acc-avatar-input" title="Schimbă poza de profil">
            <?php if ($avatarUrl !== ''): ?>
                <img src="<?= $esc($avatarUrl) ?>" alt="Poza de profil">
            <?php else: ?>
                <span class="acc-avatar-initials"><?= $esc($avatarInitials) ?></span>
            <?php endif; ?>
            <span class="acc-avatar-edit" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="M4 20h4L18.5 9.5a2.1 2.1 0 0 0-3-3L5 17v3Z"/><path d="m14.5 5.5 4 4"/></svg>
            </span>
            <span class="acc-avatar-sr">Schimbă poza de profil</span>
        </label>
        <input id="acc-avatar-input" class="acc-avatar-input" type="file" name="avatar" accept=".jpg,.jpeg,.png,.webp" data-avatar-input>
        <noscript><button class="btn btn-secondary" type="submit">Încarcă</button></noscript>
    </form>
    <?php if ($avatarUrl !== ''): ?>
        <form class="acc-avatar-remove" method="post" action="/contul-meu/poza">
            <?= \App\Support\AdminCsrf::camp() . "\n" ?>
            <input type="hidden" name="remove_avatar" value="1">
            <button type="submit">Șterge poza</button>
        </form>
    <?php endif; ?>
</div>
<script>
(function () {
    var input = document.querySelector('[data-avatar-input]');
    var form = document.querySelector('[data-avatar-form]');
    if (input && form) {
        input.addEventListener('change', function () {
            if (input.files && input.files.length > 0) {
                form.submit();
            }
        });
    }
})();
</script>
