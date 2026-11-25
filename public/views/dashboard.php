<?php
/**
 * Dashboard.
 * @var array $stats @var array $dagRunMix @var array $taskMix @var array $slots
 * @var array $health @var ?int $lastTick @var array $failures @var array $favorites
 */
$mixRows = static function (array $mix, array $order): array {
    $rows = [];
    $total = array_sum($mix);
    foreach ($order as $state) {
        $n = (int) ($mix[$state] ?? 0);
        $rows[] = [$state, $n, $total > 0 ? round($n / $total * 100, 2) : 0.0];
    }
    return [$rows, $total];
};
[$dagRows, $dagTotal] = $mixRows($dagRunMix, ['queued', 'running', 'success', 'failed']);
[$taskRows, $taskTotal] = $mixRows($taskMix, ['success', 'upstream_failed', 'skipped', 'failed', 'running', 'queued']);
$slotPct = $slots['total'] > 0 ? min(100, (int) round($slots['used'] / $slots['total'] * 100)) : 0;
?>
<header class="page-head">
  <h1><?= e(brand_name()) ?></h1>
</header>

<section class="block">
  <h2 class="section">Stats</h2>
  <div class="pill-row">
    <a class="stat-pill" href="<?= url('/dags') ?>">
      <span class="count bad"><?= $stats['failed_dags'] ?></span>
      <span>Failed Dags</span><span class="chev">›</span>
    </a>
    <a class="stat-pill" href="<?= url('/runs') ?>">
      <span class="count run"><?= $stats['running_dags'] ?></span>
      <span>Running Dags</span><span class="chev">›</span>
    </a>
    <a class="stat-pill" href="<?= url('/dags') ?>">
      <span class="count primary"><?= $stats['active_dags'] ?></span>
      <span>Active Dags</span><span class="chev">›</span>
    </a>
  </div>
</section>

<section class="block">
  <h2 class="section">★ Favorite Dags</h2>
  <?php if ($favorites): ?>
    <div class="tags">
      <?php foreach ($favorites as $fav): ?>
        <a class="tag" href="<?= url('/dags/' . rawurlencode($fav['name'])) ?>"><?= e($fav['name']) ?></a>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <p class="muted">No favorites yet. Open a Dag and star it to pin it here.</p>
  <?php endif; ?>
</section>

<section class="block health-row">
  <div>
    <h2 class="section">Health</h2>
    <div class="pill-row">
      <?php foreach (['Database' => $health['database'], 'Scheduler' => $health['scheduler'], 'Runner' => $health['runner']] as $name => $ok): ?>
        <span class="health-pill <?= $ok ? 'ok' : 'bad' ?>">
          <?= $ok ? '✓' : '✕' ?>&nbsp; <?= e($name) ?>
        </span>
      <?php endforeach; ?>
    </div>
    <?php if (!$health['scheduler']): ?>
      <p class="muted small">No recent tick — check the cron entry and <code>logs/cron.log</code>.</p>
    <?php endif; ?>
  </div>
  <div>
    <div class="row-between">
      <h2 class="section">Runner Slots</h2>
      <span class="muted small"><?= $slots['used'] ?> / <?= $slots['total'] ?></span>
    </div>
    <div class="slots-bar" title="<?= $slots['used'] ?> running of <?= $slots['total'] ?> slots">
      <span style="width: <?= $slotPct ?>%"></span>
      <em>✓&nbsp; <?= $slots['used'] ?> / <?= $slots['total'] ?></em>
    </div>
  </div>
</section>

<section class="block">
  <h2 class="section">History</h2>
  <div class="chips">
    <?php foreach (['24h' => 'Last 24 Hours', '7d' => 'Last 7 Days', '30d' => 'Last 30 Days'] as $key => $label): ?>
      <a class="chip <?= $range === $key ? 'on' : '' ?>" href="<?= url('/?range=' . $key) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
    <span class="muted small"><?= e($rangeSince) ?>  -  <?= e(date('Y-m-d H:i')) ?></span>
  </div>
</section>

<div class="dash-main">
  <div class="stack">
    <section class="card">
      <div class="card-head">
        <span class="count primary"><?= $dagTotal ?></span>
        <h3>Dag Runs</h3>
      </div>
      <?php foreach ($dagRows as [$state, $n, $pct]): ?>
        <div class="mix-row">
          <div class="row-between">
            <span class="mix-label"><span class="count <?= state_class($state) ?>"><?= $n ?></span> <?= e(state_label($state)) ?></span>
            <span class="muted small"><?= number_format($pct, 2) ?>%</span>
          </div>
          <div class="bar"><span class="<?= state_class($state) ?>" style="width: <?= $pct ?>%"></span></div>
        </div>
      <?php endforeach; ?>
    </section>

    <section class="card">
      <div class="card-head">
        <span class="count primary"><?= $taskTotal ?></span>
        <h3>Task Instances</h3>
      </div>
      <?php foreach ($taskRows as [$state, $n, $pct]): ?>
        <div class="mix-row">
          <div class="row-between">
            <span class="mix-label"><span class="count <?= state_class($state) ?>"><?= $n ?></span> <?= e(state_label($state)) ?></span>
            <span class="muted small"><?= number_format($pct, 2) ?>%</span>
          </div>
          <div class="bar"><span class="<?= state_class($state) ?>" style="width: <?= $pct ?>%"></span></div>
        </div>
      <?php endforeach; ?>
    </section>
  </div>

  <aside class="card">
    <div class="card-head">
      <span class="count bad"><?= count($failures) ?></span>
      <h3>Recent Failures</h3>
      <span class="muted small push">Newest First ▾</span>
    </div>
    <?php if (!$failures): ?>
      <p class="muted">No failures. All clear.</p>
    <?php endif; ?>
    <?php foreach ($failures as $f): ?>
      <a class="failure" href="<?= url('/logs/' . (int) $f['id']) ?>">
        <strong><?= e((string) $f['finished_at']) ?></strong>
        <span class="muted small">dag&nbsp; <?= e($f['dag_name']) ?></span>
        <span class="muted small">task&nbsp; <?= e($f['task_name']) ?> · attempt <?= (int) $f['attempt'] ?></span>
      </a>
    <?php endforeach; ?>
  </aside>
</div>
