</div> <!-- main-content -->
</div> <!-- admin-container -->
<?php $adminJsVer = @filemtime(__DIR__ . '/../assets/javascript/admin.js') ?: time(); ?>
<script src="../assets/javascript/admin.js?v=<?= $adminJsVer ?>"></script>
</body>
</html>
