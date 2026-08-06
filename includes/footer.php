    </main>

    <!-- Footer -->
    <footer class="bg-white border-t border-gray-200 mt-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <div class="flex flex-col md:flex-row justify-between items-center">
                <!-- Copyright -->
                <div class="text-center md:text-left mb-4 md:mb-0">
                    <p class="text-sm text-gray-600">
                        &copy; 2025 IIT Bombay - PhD Time-Off Portal
                    </p>
                    <p class="text-xs text-gray-500 mt-1">
                        All rights reserved
                    </p>
                </div>

                <!-- Links -->
                <div class="flex flex-wrap justify-center gap-4">
                    <a href="https://www.som.iitb.ac.in" class="text-sm text-blue-600 hover:text-blue-800 hover:underline">
                        <i class="fas fa-question-circle mr-1"></i>
                        Shailesh J. Mehta School of Management
                    </a>
                </div>
            </div>

            <!-- Additional Info -->
            <div class="mt-6 text-center border-t border-gray-200 pt-4">
                <p class="text-xs text-gray-500">
                    <i class="fas fa-info-circle mr-1"></i>
                    For technical support, please contact
                    <a href="mailto:<?php echo ADMIN_EMAIL; ?>" class="text-blue-600 hover:underline">
                        <?php echo ADMIN_EMAIL; ?>
                    </a>
                </p>
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
