<?php

declare(strict_types=1);
?>
</div>
<script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
<?php if (!empty($loadChartJs)): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<?php endif; ?>
<script src="assets/js/app.js"></script>
<?php if (!empty($loadStocksJs)): ?>
<script src="assets/js/stocks.js?v=20260323e"></script>
<?php endif; ?>
<?php if (!empty($loadPredictionsJs)): ?>
<script src="assets/js/predictions.js?v=20260323c"></script>
<?php endif; ?>
<?php if (!empty($loadDashboardJs)): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script src="assets/js/dashboard.js"></script>
<?php endif; ?>
<?php if (!empty($loadSettingsJs)): ?>
<script src="assets/js/settings.js"></script>
<?php endif; ?>
<?php if (!empty($loadMutualFundsJs)): ?>
<script src="assets/js/mutual-funds.js"></script>
<?php endif; ?>
<?php if (!empty($loadSyncCenterJs)): ?>
<script src="assets/js/sync-worker-client.js?v=20260323f"></script>
<script src="assets/js/sync-center.js?v=20260323i"></script>
<?php endif; ?>
</body>
</html>
