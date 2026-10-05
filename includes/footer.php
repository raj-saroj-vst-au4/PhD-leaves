    </main>

    <!-- Footer -->
    <footer class="mt-16 border-t border-gray-200 bg-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
            <div class="flex flex-col items-center gap-3 text-center text-sm text-gray-500 md:flex-row md:justify-between md:text-left">
                <p>
                    &copy; <?php echo date('Y'); ?> IIT Bombay &middot; PhD Time-Off Portal
                </p>
                <div class="flex flex-wrap items-center justify-center gap-x-5 gap-y-1">
                    <a href="https://www.som.iitb.ac.in" class="hover:text-gray-900 transition-colors" target="_blank" rel="noopener">
                        Shailesh J. Mehta School of Management
                    </a>
                    <a href="mailto:<?php echo ADMIN_EMAIL; ?>" class="inline-flex items-center gap-1.5 hover:text-gray-900 transition-colors">
                        <i class="fas fa-life-ring text-xs"></i> Technical support
                    </a>
                </div>
            </div>
        </div>
    </footer>

    <!-- Custom JavaScript -->
    <script src="<?php echo asset_url('public/js/main.js'); ?>"></script>

    <!-- Page-specific scripts can be added here -->
    <?php if (isset($pageScripts)): ?>
        <?php echo $pageScripts; ?>
    <?php endif; ?>

</body>
</html>
