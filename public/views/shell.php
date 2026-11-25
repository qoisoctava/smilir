<?php
/** @var array $user @var string $title @var string $content @var string $nav */
$nav = $nav ?? '';
$items = [
    ['home',   '/',        '⌂', 'Home'],
    ['dags',   '/dags',    '⬡', 'Dags'],
    ['runs',   '/runs',    '▶', 'Runs'],
    ['logs',   '/logs',    '☰', 'Logs'],
    ['python', '/python',  '>_', 'Python'],
    ['admin',  '/admin',   '⚙', 'Admin'],
];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · <?= e(brand_name()) ?></title>
<link rel="icon" href="<?= url(brand_icon()) ?>">
<link rel="stylesheet" href="<?= url('/assets/app.css') ?>">
</head>
<body>
<div class="app">
  <aside class="sidebar">
    <a class="logo" href="<?= url('/') ?>" title="<?= e(brand_name()) ?>">
      <img src="<?= url(brand_logo()) ?>" alt="<?= e(brand_name()) ?>">
    </a>
    <nav class="nav">
      <?php foreach ($items as [$key, $href, $glyph, $label]): ?>
        <a class="nav-item <?= $nav === $key ? 'active' : '' ?>" href="<?= url($href) ?>">
          <span class="glyph"><?= e($glyph) ?></span>
          <span class="label"><?= e($label) ?></span>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="nav-bottom">
      <a class="nav-item" href="<?= url('/docs') ?>" title="Bundled guides">
        <span class="glyph">❑</span><span class="label">Docs</span>
      </a>
      <a class="nav-user" href="<?= url('/account/password') ?>"
         title="Signed in as <?= e($user['username'] ?? 'User') ?> — change password">
        <span class="glyph">◯</span>
        <span class="label"><?= e($user['username'] ?? 'User') ?></span>
      </a>
      <form method="post" action="<?= url('/logout') ?>">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <button class="nav-item" type="submit" title="Sign out">
          <span class="glyph">⏻</span>
          <span class="label">Logout</span>
        </button>
      </form>
    </div>
  </aside>

  <main class="content">
    <?php if ($flash): ?>
      <div class="flash <?= e($flash['kind']) ?>"><?= e($flash['message']) ?></div>
    <?php endif; ?>
    <?= $content ?>
  </main>
</div>
<div class="cmdk" id="cmdk" hidden>
  <form class="cmdk-panel" method="get" action="<?= url('/search') ?>">
    <input name="q" id="cmdk-input" placeholder="Search Dags, runs, tasks…" autocomplete="off">
    <div class="cmdk-foot"><span>↵ search</span><span>esc close</span><span>⌘K / Ctrl-K</span></div>
  </form>
</div>
<script>
(() => {
  const modal = document.getElementById('cmdk');
  const input = document.getElementById('cmdk-input');
  if (!modal || !input) return;
  const open = () => { modal.hidden = false; input.focus(); };
  const close = () => { modal.hidden = true; };
  document.addEventListener('keydown', (e) => {
    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
      e.preventDefault();
      modal.hidden ? open() : close();
    } else if (e.key === 'Escape' && !modal.hidden) {
      close();
    }
  });
  modal.addEventListener('click', (e) => { if (e.target === modal) close(); });
})();
</script>
</body>
</html>
