<?php
/*
Plugin Name: Newsletter Manager
Description: 
Version: 2.1
Author: Bevan matsacos
*/

// Prevent direct access to this file
if (!defined('ABSPATH')) {
    exit;
}

// Add menu and submenu to admin panel
function newsletter_manager_menu() {
    // Add main menu page
    add_menu_page(
        'Newsletter Manager', // Page title
        'Newsletter Manager', // Menu title
        'manage_options', // Capability required
        'newsletter-manager', // Menu slug
        'newsletter_manager_dashboard', // Function to display the page
        'dashicons-admin-generic', // Icon
        100 // Position in menu
    );

    // Add Newsletters submenu (will be the main list and add form)
    add_submenu_page(
        'newsletter-manager', // Parent slug
        'Newsletters', // Page title
        'Newsletters', // Menu title
        'manage_options', // Capability required
        'newsletter-manager', // Menu slug (same as parent to make it the default)
        'newsletter_manager_dashboard' // Function to display the page
    );

    // Add Settings submenu
    add_submenu_page(
        'newsletter-manager', // Parent slug
        'Newsletter Manager Settings', // Page title
        'Settings', // Menu title
        'manage_options', // Capability required
        'newsletter-manager-settings', // Menu slug
        'newsletter_manager_settings_page' // Function to display the page
    );

    // Add Archive submenu
    add_submenu_page(
        'newsletter-manager', // Parent slug
        'Newsletter Archive', // Page title
        'Archive', // Menu title
        'manage_options', // Capability required
        'newsletter-manager-archive', // Menu slug
        'newsletter_manager_archive_page' // Function to display the page
    );
}
add_action('admin_menu', 'newsletter_manager_menu');

// Create tables on plugin activation
function newsletter_manager_create_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'newsletter_manager_entries';
    $archive_table_name = $wpdb->prefix . 'newsletter_manager_entries_archive';
    
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        display_name varchar(100) NOT NULL,
        file_name varchar(100) NOT NULL,
        week int(2) NOT NULL,
        term int(1) NOT NULL,
        year int(4) NOT NULL DEFAULT YEAR(CURRENT_TIMESTAMP),
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    $archive_sql = "CREATE TABLE IF NOT EXISTS $archive_table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        display_name varchar(100) NOT NULL,
        file_name varchar(100) NOT NULL,
        week int(2) NOT NULL,
        term int(1) NOT NULL,
        year int(4) NOT NULL,
        created_at datetime NOT NULL,
        archived_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    dbDelta($archive_sql);

    // Check if year column exists, if not add it
    $row = $wpdb->get_results("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_SCHEMA = '" . DB_NAME . "' 
        AND TABLE_NAME = '$table_name' 
        AND COLUMN_NAME = 'year'");
        
    if(empty($row)){
        $wpdb->query("ALTER TABLE $table_name ADD year int(4) NOT NULL DEFAULT YEAR(CURRENT_TIMESTAMP) AFTER term");
    }
}

// Create plugin directory on activation
register_activation_hook(__FILE__, 'newsletter_manager_create_directory');

function newsletter_manager_create_directory() {
    // Create database table
    newsletter_manager_create_table();
    
    // Create uploads directory in plugin folder
    $upload_dir = plugin_dir_path(__FILE__) . 'uploads';
    if (!file_exists($upload_dir)) {
        wp_mkdir_p($upload_dir);
        // Create .htaccess to protect direct access if needed
        file_put_contents($upload_dir . '/.htaccess', 'Options -Indexes');
    }
}

// Helper function to get plugin upload directory
function newsletter_manager_get_upload_dir() {
    return array(
        'path' => plugin_dir_path(__FILE__) . 'uploads',
        'url' => plugin_dir_url(__FILE__) . 'uploads'
    );
}

// Create the dashboard/main newsletters page
function newsletter_manager_dashboard() {
    if (!current_user_can('manage_options')) {
        wp_die('You do not have sufficient permissions to access this page.');
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'newsletter_manager_entries';

    // Handle delete action
    if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
        $id = intval($_GET['id']);
        if (isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'delete_newsletter_' . $id)) {
            // Get file name before deleting the entry
            $entry = $wpdb->get_row($wpdb->prepare("SELECT file_name FROM $table_name WHERE id = %d", $id));
            
            if ($entry) {
                // Delete the file
                $upload_dir = newsletter_manager_get_upload_dir();
                $file_path = $upload_dir['path'] . '/' . $entry->file_name;
                if (file_exists($file_path)) {
                    unlink($file_path);
                }
                
                // Delete the database entry
                $wpdb->delete(
                    $table_name,
                    array('id' => $id),
                    array('%d')
                );
                
                echo '<div class="notice notice-success is-dismissible"><p>Newsletter deleted successfully!</p></div>';
            }
        } else {
            echo '<div class="notice notice-error is-dismissible"><p>Security check failed.</p></div>';
        }
    }

    // Handle update newsletter submission
    if (isset($_POST['update_newsletter'])) {
        if (!isset($_POST['update_newsletter_nonce']) || 
            !wp_verify_nonce($_POST['update_newsletter_nonce'], 'update_newsletter_action')) {
            wp_die('Security check failed');
        }

        $id = intval($_POST['entry_id']);
        $display_name = sanitize_text_field($_POST['display_name']);
        $week = intval($_POST['week']);
        $term = intval($_POST['term']);
        $year = intval($_POST['year']);

        $update_data = array(
            'display_name' => $display_name,
            'week' => $week,
            'term' => $term,
            'year' => $year
        );
        $update_format = array('%s', '%d', '%d', '%d');

        $upload_dir = newsletter_manager_get_upload_dir();
        
        // Get current file information
        $current_entry = $wpdb->get_row($wpdb->prepare("SELECT file_name FROM $table_name WHERE id = %d", $id));
        $old_file_path = $upload_dir['path'] . '/' . $current_entry->file_name;
        $file_extension = pathinfo($current_entry->file_name, PATHINFO_EXTENSION);

        // Handle file upload if a new file was provided (this takes precedence over renaming)
        if (!empty($_FILES['newsletter_file']['name'])) {
            $file = $_FILES['newsletter_file'];
            
            // Upload new file
            $file_info = pathinfo($file['name']);
            $new_filename = sanitize_file_name($file_info['filename'] . '.' . $file_info['extension']);
            
            if (move_uploaded_file($file['tmp_name'], $upload_dir['path'] . '/' . $new_filename)) {
                // Delete old file
                if (file_exists($old_file_path)) {
                    unlink($old_file_path);
                }
                
                // Update database with new filename
                $update_data['file_name'] = $new_filename;
                $update_format[] = '%s';
            } else {
                echo '<div class="notice notice-error is-dismissible"><p>Error uploading file.</p></div>';
                return;
            }
        }
        // Handle file renaming if a new filename was provided and no file upload
        else if (!empty($_POST['new_filename'])) {
            $new_filename_base = sanitize_file_name($_POST['new_filename']);
            $new_filename = $new_filename_base . '.' . $file_extension;
            $new_file_path = $upload_dir['path'] . '/' . $new_filename;
            
            // Check if target filename already exists
            if (file_exists($new_file_path) && $new_filename !== $current_entry->file_name) {
                echo '<div class="notice notice-error is-dismissible"><p>A file with this name already exists. Please choose a different name.</p></div>';
                return;
            }
            
            // Rename the file
            if (rename($old_file_path, $new_file_path)) {
                // Update database with new filename
                $update_data['file_name'] = $new_filename;
                $update_format[] = '%s';
            } else {
                echo '<div class="notice notice-error is-dismissible"><p>Error renaming file.</p></div>';
                return;
            }
        }

        // Update database
        $result = $wpdb->update(
            $table_name,
            $update_data,
            array('id' => $id),
            $update_format,
            array('%d')
        );

        if ($result !== false) {
            echo '<div class="notice notice-success is-dismissible"><p>Newsletter updated successfully!</p></div>';
        } else {
            echo '<div class="notice notice-error is-dismissible"><p>Error updating newsletter.</p></div>';
        }
    }

    // Handle form submission
    if (isset($_POST['add_newsletter'])) {
        error_log('Newsletter form submitted');  // Debug log
        
        if (!isset($_POST['add_newsletter_nonce']) || !wp_verify_nonce($_POST['add_newsletter_nonce'], 'add_newsletter_action')) {
            error_log('Nonce verification failed');  // Debug log
            wp_die('Security check failed');
        }

        error_log('File upload details: ' . print_r($_FILES, true));  // Debug log

        if (!empty($_FILES['newsletter_file']['name'])) {
            $upload_dir = newsletter_manager_get_upload_dir();
            $file = $_FILES['newsletter_file'];
            
            if ($file['error'] === UPLOAD_ERR_OK) {
                $file_info = pathinfo($file['name']);
                $new_filename = sanitize_file_name($file_info['filename'] . '.' . $file_info['extension']);
                
                // Check if file already exists
                if (file_exists($upload_dir['path'] . '/' . $new_filename)) {
                    echo '<div class="notice notice-error is-dismissible"><p>A file with this name already exists. Please rename the file and try again.</p></div>';
                    return;
                }
                
                if (move_uploaded_file($file['tmp_name'], $upload_dir['path'] . '/' . $new_filename)) {
                    // Get the year from the form
                    $year = intval($_POST['year']);
                    $current_year = intval(date('Y'));
                    
                    // Determine which table to use based on the year
                    $target_table = ($year < $current_year) ? $wpdb->prefix . 'newsletter_manager_entries_archive' : $wpdb->prefix . 'newsletter_manager_entries';
                    
                    // Prepare the data for insertion
                    $insert_data = array(
                        'display_name' => sanitize_text_field($_POST['display_name']),
                        'file_name' => $new_filename,
                        'week' => intval($_POST['week']),
                        'term' => intval($_POST['term']),
                        'year' => $year,
                        'created_at' => current_time('mysql')
                    );
                    
                    // Add archived_at if it's going to the archive table
                    if ($year < $current_year) {
                        $insert_data['archived_at'] = current_time('mysql');
                    }
                    
                    // Insert into the appropriate table
                    $result = $wpdb->insert(
                        $target_table,
                        $insert_data,
                        array('%s', '%s', '%d', '%d', '%d', '%s', '%s')
                    );
                    
                    if ($result === false) {
                        echo '<div class="notice notice-error is-dismissible"><p>Error saving newsletter to database.</p></div>';
                    } else {
                        $message = ($year < $current_year) ? 
                            'Newsletter added and archived successfully!' : 
                            'Newsletter added successfully!';
                        echo '<div class="notice notice-success is-dismissible"><p>' . $message . '</p></div>';
                    }
                } else {
                    echo '<div class="notice notice-error is-dismissible"><p>Error uploading file.</p></div>';
                }
            } else {
                echo '<div class="notice notice-error is-dismissible"><p>File upload error: ' . $file['error'] . '</p></div>';
            }
        } else {
            error_log('No file uploaded');  // Debug log
            echo '<div class="notice notice-error is-dismissible"><p>Please select a file to upload.</p></div>';
        }
    }

    // Handle bulk upload
    if (isset($_POST['bulk_upload'])) {
        if (!isset($_POST['bulk_upload_nonce']) || !wp_verify_nonce($_POST['bulk_upload_nonce'], 'bulk_upload_action')) {
            wp_die('Security check failed');
        }

        $upload_dir = newsletter_manager_get_upload_dir();
        $files = $_FILES['newsletter_files'];
        $success_count = 0;
        $error_count = 0;

        // Loop through each uploaded file
        for ($i = 0; $i < count($files['name']); $i++) {
            if ($files['error'][$i] === UPLOAD_ERR_OK) {
                $file_name = $files['name'][$i];
                $tmp_name = $files['tmp_name'][$i];
                
                // Extract information from filename
                $file_info = pathinfo($file_name);
                $name_without_ext = $file_info['filename'];
                
                // Try to extract term, week, and year from filename
                $term = '';
                $week = '';
                $year = '';
                
                // Try new format first (Tm1Wk9...)
                if (preg_match('/Tm(\d+)Wk(\d+).*?(\d{2})$/', $name_without_ext, $matches)) {
                    $term = $matches[1];
                    $week = $matches[2];
                    $year = '20' . $matches[3];
                } 
                // Try alternative formats
                else {
                    // Try to find term
                    if (preg_match('/Term\s*(\d+)|T(\d+)/i', $name_without_ext, $matches)) {
                        $term = $matches[1] ?? $matches[2];
                    }
                    
                    // Try to find week
                    if (preg_match('/Week\s*(\d+)|W(\d+)/i', $name_without_ext, $matches)) {
                        $week = $matches[1] ?? $matches[2];
                    }
                    
                    // Try to find year (2-digit or 4-digit)
                    if (preg_match('/(?:20)?(\d{2})$/', $name_without_ext, $matches)) {
                        $year = '20' . $matches[1];
                    }
                }
                
                // Validate extracted information
                if (empty($term) || empty($week) || empty($year)) {
                    $error_count++;
                    echo '<div class="notice notice-error is-dismissible"><p>Error processing file ' . esc_html($file_name) . ': Could not extract term, week, or year from filename.</p></div>';
                    continue;
                }
                
                // Create new filename (keep original name)
                $new_filename = sanitize_file_name($file_info['filename'] . '.' . $file_info['extension']);
                
                // Check if file already exists
                if (file_exists($upload_dir['path'] . '/' . $new_filename)) {
                    $error_count++;
                    echo '<div class="notice notice-error is-dismissible"><p>File ' . esc_html($new_filename) . ' already exists.</p></div>';
                    continue;
                }
                
                // Move file to uploads directory
                if (move_uploaded_file($tmp_name, $upload_dir['path'] . '/' . $new_filename)) {
                    // Format display name according to template
                    $display_name_format = get_option('newsletter_manager_display_name_format', 'Week [Wk], Term [Tm] [Yr] - [Name]');
                    $display_name = str_replace(
                        ['[Wk]', '[Tm]', '[Yr]', '[Name]'],
                        [$week, $term, $year, $name_without_ext],
                        $display_name_format
                    );
                    
                    // Insert into database
                    $result = $wpdb->insert(
                        $table_name,
                        array(
                            'display_name' => $display_name,
                            'file_name' => $new_filename,
                            'week' => intval($week),
                            'term' => intval($term),
                            'year' => intval($year),
                            'created_at' => current_time('mysql')
                        ),
                        array('%s', '%s', '%d', '%d', '%d', '%s')
                    );
                    
                    if ($result) {
                        $success_count++;
                    } else {
                        $error_count++;
                        echo '<div class="notice notice-error is-dismissible"><p>Database error for file ' . esc_html($file_name) . '</p></div>';
                    }
                } else {
                    $error_count++;
                    echo '<div class="notice notice-error is-dismissible"><p>Error moving file ' . esc_html($file_name) . '</p></div>';
                }
            } else {
                $error_count++;
                echo '<div class="notice notice-error is-dismissible"><p>Upload error for file ' . esc_html($files['name'][$i]) . '</p></div>';
            }
        }
        
        // Show summary
        if ($success_count > 0) {
            echo '<div class="notice notice-success is-dismissible"><p>Successfully uploaded ' . $success_count . ' file(s).</p></div>';
        }
        if ($error_count > 0) {
            echo '<div class="notice notice-error is-dismissible"><p>Failed to upload ' . $error_count . ' file(s).</p></div>';
        }
    }

    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline"><?php echo esc_html(get_admin_page_title()); ?></h1>
        
        <!-- Add action buttons -->
        <a href="#" class="page-title-action" onclick="showAddNewForm()">Add New</a>
        <a href="#" class="page-title-action" onclick="showBulkUploadForm()">Bulk Upload</a>
        <hr class="wp-header-end">

        <!-- Add New Newsletter Form (hidden by default) -->
        <div id="add-new-form" style="display: none; margin-top: 20px; padding: 20px; background: #f8f9fa; border: 1px solid #ddd;">
            <h3>Add New Newsletter</h3>
            <form method="post" enctype="multipart/form-data">
                <?php wp_nonce_field('add_newsletter_action', 'add_newsletter_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">File</th>
                        <td>
                            <input type="file" 
                                   name="newsletter_file" 
                                   id="newsletter_file" 
                                   accept=".pdf" 
                                   required 
                                   onchange="parseFileName(this)">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Week</th>
                        <td>
                            <select name="week" id="week" required>
                                <?php for ($i = 1; $i <= 11; $i++) : ?>
                                    <option value="<?php echo $i; ?>">Week <?php echo $i; ?></option>
                                <?php endfor; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Term</th>
                        <td>
                            <select name="term" id="term" required>
                                <?php for ($i = 1; $i <= 4; $i++) : ?>
                                    <option value="<?php echo $i; ?>">Term <?php echo $i; ?></option>
                                <?php endfor; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Year</th>
                        <td>
                            <select name="year" id="year" required>
                                <?php 
                                $current_year = date('Y');
                                for ($i = $current_year; $i >= $current_year - 5; $i--) : 
                                ?>
                                    <option value="<?php echo $i; ?>"><?php echo $i; ?></option>
                                <?php endfor; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Display Name</th>
                        <td>
                            <input type="text" 
                                   name="display_name" 
                                   id="display_name" 
                                   class="regular-text" 
                                   required>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <input type="submit" 
                           name="add_newsletter" 
                           class="button button-primary" 
                           value="Add Newsletter">
                    <button type="button" class="button" onclick="hideAddNewForm()">Cancel</button>
                </p>
            </form>
        </div>

        <!-- Bulk Upload Form (hidden by default) -->
        <div id="bulk-upload-form" style="display: none; margin-top: 20px; padding: 20px; background: #f8f9fa; border: 1px solid #ddd;">
            <h3>Bulk Upload Newsletters</h3>
            <form method="post" enctype="multipart/form-data">
                <?php wp_nonce_field('bulk_upload_action', 'bulk_upload_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">Files</th>
                        <td>
                            <input type="file" 
                                   name="newsletter_files[]" 
                                   multiple 
                                   accept=".pdf">
                            <p class="description">Select multiple PDF files to upload. The term, year, and week will be extracted from the filenames.</p>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <input type="submit" 
                           name="bulk_upload" 
                           class="button button-primary" 
                           value="Upload Files">
                    <button type="button" class="button" onclick="hideBulkUploadForm()">Cancel</button>
                </p>
            </form>
        </div>

        <!-- Add JavaScript for form toggling -->
        <script>
            // Add this at the beginning of your script to get the format template
            var displayNameFormat = '<?php echo esc_js(get_option('newsletter_manager_display_name_format', 'Week [Wk], Term [Tm] [Yr] - [Name]')); ?>';

            function showAddNewForm() {
                document.getElementById('add-new-form').style.display = 'block';
                document.getElementById('bulk-upload-form').style.display = 'none';
            }

            function hideAddNewForm() {
                document.getElementById('add-new-form').style.display = 'none';
            }

            function showBulkUploadForm() {
                document.getElementById('bulk-upload-form').style.display = 'block';
                document.getElementById('add-new-form').style.display = 'none';
            }

            function hideBulkUploadForm() {
                document.getElementById('bulk-upload-form').style.display = 'none';
            }
            
            // Toggle inline edit form visibility
            function toggleEditForm(id) {
                var editRow = document.getElementById('edit-row-' + id);
                if (editRow.style.display === 'none' || editRow.style.display === '') {
                    // Hide any other open edit forms first
                    var allEditRows = document.getElementsByClassName('edit-form-row');
                    for (var i = 0; i < allEditRows.length; i++) {
                        allEditRows[i].style.display = 'none';
                    }
                    // Show this edit form
                    editRow.style.display = 'table-row';
                    
                    // Optional: highlight the row being edited
                    document.getElementById('row-' + id).style.backgroundColor = '#f0f7ff';
                } else {
                    // Hide this edit form
                    editRow.style.display = 'none';
                    
                    // Remove highlight
                    document.getElementById('row-' + id).style.backgroundColor = '';
                }
            }

            function formatDisplayName(fileName, week, term, year) {
                var format = displayNameFormat;
                
                // Replace the placeholders with actual values
                format = format.replace('[Wk]', week || '');
                format = format.replace('[Tm]', term || '');
                format = format.replace('[Yr]', year || '');
                format = format.replace('[Name]', fileName || '');
                
                return format;
            }

            function parseFileName(input) {
                if (input.files && input.files[0]) {
                    var fileName = input.files[0].name;

                    // Remove file extension
                    fileName = fileName.replace(/\.[^/.]+$/, "");

                    var weekNum = '';
                    var termNum = '';
                    var year = '';

                    // 1. Try the "New Format" (e.g., Tm1Wk9...25)
                    var newFormatMatch = fileName.match(/Tm(\d+)Wk(\d+)/i);

                    if (newFormatMatch) {
                        termNum = newFormatMatch[1];
                        weekNum = newFormatMatch[2];
                        document.getElementById('term').value = termNum;
                        document.getElementById('week').value = weekNum;

                        // Try to find year in format "25" or "2025" at the end
                        var yearMatch = fileName.match(/(\d{2})$/);
                        if (yearMatch) {
                            year = '20' + yearMatch[1];
                            document.getElementById('year').value = year;
                        }
                    } 
                    else {
                        // 2. Try the 6-digit format (WWTTYY) - e.g., 010226.pdf
                        // This looks for exactly 6 digits at the start of the filename
                        var sixDigitMatch = fileName.match(/^(\d{2})(\d{2})(\d{2})/);

                        if (sixDigitMatch) {
                            weekNum = parseInt(sixDigitMatch[1], 10); // Week (e.g., 01 -> 1)
                            termNum = parseInt(sixDigitMatch[2], 10); // Term (e.g., 02 -> 2)
                            year = '20' + sixDigitMatch[3];           // Year (e.g., 26 -> 2026)

                            document.getElementById('week').value = weekNum;
                            document.getElementById('term').value = termNum;
                            document.getElementById('year').value = year;
                        } 
                        else {
                            // 3. Fallback to loose formats (Week 5, W5, Term 2, T2)
                            
                            // Week number
                            var weekMatch = fileName.match(/Week\s*(\d+)|W(\d+)/i);
                            if (weekMatch) {
                                weekNum = weekMatch[1] ?? weekMatch[2];
                                document.getElementById('week').value = weekNum;
                            }

                            // Term number
                            var termMatch = fileName.match(/Term\s*(\d+)|T(\d+)/i);
                            if (termMatch) {
                                termNum = termMatch[1] || termMatch[2];
                                document.getElementById('term').value = termNum;
                            }

                            // Year (4-digit format, e.g., 2026)
                            var yearMatch = fileName.match(/(20\d{2})/);
                            if (yearMatch) {
                                year = yearMatch[1];
                                document.getElementById('year').value = year;
                            }
                        }
                    }

                    // Format the display name according to the template
                    var displayName = formatDisplayName(fileName, weekNum, termNum, year);
                    document.getElementById('display_name').value = displayName;
                }
            }
        </script>

        <!-- Current Newsletters Table -->
        <div style="margin-top: 20px;">
            <h3>Current Newsletters</h3>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th scope="col">Display Name</th>
                        <th scope="col">File Name</th>
                        <th scope="col">Week</th>
                        <th scope="col">Term</th>
                        <th scope="col">Year</th>
                        <th scope="col">Created Date</th>
                        <th scope="col">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $entries = $wpdb->get_results("SELECT * FROM $table_name ORDER BY year DESC, term ASC, week ASC");
                    foreach ($entries as $entry) :
                        $delete_nonce = wp_create_nonce('delete_newsletter_' . $entry->id);
                        $edit_nonce = wp_create_nonce('edit_newsletter_' . $entry->id);
                    ?>
                        <tr id="row-<?php echo $entry->id; ?>">
                            <td><?php echo esc_html($entry->display_name); ?></td>
                            <td><?php echo esc_html($entry->file_name); ?></td>
                            <td>Week <?php echo esc_html($entry->week); ?></td>
                            <td>Term <?php echo esc_html($entry->term); ?></td>
                            <td><?php echo esc_html($entry->year); ?></td>
                            <td><?php echo esc_html(date('Y-m-d', strtotime($entry->created_at))); ?></td>
                            <td>
                                <a href="#" class="button button-small" onclick="toggleEditForm(<?php echo $entry->id; ?>); return false;">Edit</a>
                                <a href="?page=newsletter-manager&action=delete&id=<?php echo $entry->id; ?>&_wpnonce=<?php echo $delete_nonce; ?>" 
                                   class="button button-small button-link-delete" 
                                   onclick="return confirm('Are you sure you want to delete this newsletter?');">Delete</a>
                            </td>
                        </tr>
                        <!-- Add edit form row (hidden by default) -->
                        <tr id="edit-row-<?php echo $entry->id; ?>" class="edit-form-row" style="display: none;">
                            <td colspan="7" style="padding: 15px; background-color: #f9f9f9; border-bottom: 1px solid #ddd;">
                                <form method="post" enctype="multipart/form-data">
                                    <?php wp_nonce_field('update_newsletter_action', 'update_newsletter_nonce'); ?>
                                    <input type="hidden" name="entry_id" value="<?php echo $entry->id; ?>">
                                    <table class="form-table">
                                        <tr>
                                            <th scope="row">Current File</th>
                                            <td>
                                                <?php echo esc_html($entry->file_name); ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row">Rename File</th>
                                            <td>
                                                <input type="text" 
                                                       name="new_filename" 
                                                       class="regular-text" 
                                                       placeholder="New filename (without extension)">
                                                <p class="description">Leave blank to keep the current filename. Enter only the name without .pdf extension.</p>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row">Or Replace File</th>
                                            <td>
                                                <input type="file" name="newsletter_file" accept=".pdf">
                                                <p class="description">Upload a new file to replace the current one. This will override the rename option above.</p>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row">Week</th>
                                            <td>
                                                <select name="week" required>
                                                    <?php for ($i = 1; $i <= 11; $i++) : ?>
                                                        <option value="<?php echo $i; ?>" <?php selected($entry->week, $i); ?>>Week <?php echo $i; ?></option>
                                                    <?php endfor; ?>
                                                </select>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row">Term</th>
                                            <td>
                                                <select name="term" required>
                                                    <?php for ($i = 1; $i <= 4; $i++) : ?>
                                                        <option value="<?php echo $i; ?>" <?php selected($entry->term, $i); ?>>Term <?php echo $i; ?></option>
                                                    <?php endfor; ?>
                                                </select>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row">Year</th>
                                            <td>
                                                <select name="year" required>
                                                    <?php 
                                                    $current_year = date('Y');
                                                    for ($i = $current_year; $i >= $current_year - 5; $i--) : 
                                                    ?>
                                                        <option value="<?php echo $i; ?>" <?php selected($entry->year, $i); ?>><?php echo $i; ?></option>
                                                    <?php endfor; ?>
                                                </select>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row">Display Name</th>
                                            <td>
                                                <input type="text" name="display_name" class="regular-text" required value="<?php echo esc_attr($entry->display_name); ?>">
                                            </td>
                                        </tr>
                                    </table>
                                    <p class="submit">
                                        <input type="submit" name="update_newsletter" class="button button-primary" value="Update Newsletter">
                                        <button type="button" class="button" onclick="toggleEditForm(<?php echo $entry->id; ?>)">Cancel</button>
                                    </p>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($entries)) : ?>
                        <tr>
                            <td colspan="7">No newsletters found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
}

// Create the settings page content
function newsletter_manager_settings_page() {
    if (!current_user_can('manage_options')) {
        wp_die('You do not have sufficient permissions to access this page.');
    }
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'newsletter_manager_entries';
    $archive_table_name = $wpdb->prefix . 'newsletter_manager_entries_archive';
    
    // Debug force archive (add this at the top)
    if (isset($_POST['debug_archive'])) {
        echo '<div style="background: #f0f0f0; padding: 15px; border: 1px solid #ccc; margin: 20px 0;">';
        echo '<h3>🔍 Archive Debug Information</h3>';
        
        // 1. Show current entries to diagnose
        $entries = $wpdb->get_results("SELECT * FROM $table_name WHERE year = 2024");
        echo '<p>Found ' . count($entries) . ' entries from 2024</p>';
        
        if (count($entries) > 0) {
            echo '<table style="width:100%; border-collapse: collapse; margin-bottom: 20px;">';
            echo '<tr style="background:#eee;"><th>ID</th><th>Display Name</th><th>File Name</th><th>Week</th><th>Term</th><th>Year</th><th>Created</th></tr>';
            
            foreach ($entries as $entry) {
                echo '<tr style="border-bottom:1px solid #ddd;">';
                echo '<td>' . $entry->id . '</td>';
                echo '<td>' . $entry->display_name . '</td>';
                echo '<td>' . $entry->file_name . '</td>';
                echo '<td>' . $entry->week . '</td>';
                echo '<td>' . $entry->term . '</td>';
                echo '<td>' . $entry->year . ' (' . gettype($entry->year) . ')</td>';
                echo '<td>' . $entry->created_at . '</td>';
                echo '</tr>';
            }
            echo '</table>';
            
            // 2. Try to archive the first entry directly
            $entry = $entries[0];
            echo '<h4>Attempting to archive entry ID: ' . $entry->id . '</h4>';
            
            // Insert into archive table directly
            $result = $wpdb->insert(
                $archive_table_name,
                array(
                    'display_name' => $entry->display_name,
                    'file_name' => $entry->file_name,
                    'week' => intval($entry->week),
                    'term' => intval($entry->term),
                    'year' => intval($entry->year),
                    'created_at' => $entry->created_at,
                    'archived_at' => current_time('mysql')
                ),
                array('%s', '%s', '%d', '%d', '%d', '%s', '%s')
            );
            
            if ($result === false) {
                echo '<p style="color:red;">❌ Failed to insert into archive table!</p>';
                echo '<p>Error: ' . $wpdb->last_error . '</p>';
            } else {
                echo '<p style="color:green;">✓ Successfully inserted into archive table</p>';
                
                // Delete from main table
                $delete_result = $wpdb->delete(
                    $table_name,
                    array('id' => $entry->id),
                    array('%d')
                );
                
                if ($delete_result === false) {
                    echo '<p style="color:red;">❌ Failed to delete from main table!</p>';
                    echo '<p>Error: ' . $wpdb->last_error . '</p>';
                } else {
                    echo '<p style="color:green;">✓ Successfully deleted from main table</p>';
                    echo '<p>Entry has been archived successfully!</p>';
                }
            }
        }
        
        echo '</div>';
    }
    
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        
        <!-- Admin Settings -->
        <div style="margin-top: 20px; padding: 20px; background: #f8f9fa; border: 1px solid #ddd;">
            <h3>Settings</h3>
            <form method="post" action="">
                <?php wp_nonce_field('admin_settings_action', 'admin_settings_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row" colspan="2">
                            <h4>Display Name Format</h4>
                            <p class="description">
                                Use these keys in your format:<br>
                                [Tm] = Term number<br>
                                [Wk] = Week number<br>
                                [Yr] = Year (20XX)<br>
                                [Name] = Original filename (without extension)
                            </p>
                        </th>
                    </tr>
                    <tr>
                        <th scope="row">Format Template</th>
                        <td>
                            <input type="text" 
                                   name="display_name_format" 
                                   class="regular-text"
                                   value="<?php echo esc_attr(get_option('newsletter_manager_display_name_format', 'Week [Wk], Term [Tm] [Yr] - [Name]')); ?>"
                                   placeholder="Week [Wk], Term [Tm] [Yr] - [Name]">
                            <p class="description">Example output: "Week 8, Term 1 2025 - WHNews20thMar"</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Auto Archive</th>
                        <td>
                            <label>
                                <input type="checkbox" 
                                       name="auto_archive_enabled" 
                                       value="1" 
                                       <?php checked(get_option('newsletter_manager_auto_archive', '0'), '1'); ?>>
                                Automatically archive newsletters from previous years
                            </label>
                            <p class="description">When enabled, newsletters from previous years will be automatically moved to the archive.</p>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <input type="submit" name="save_admin_settings" class="button button-primary" value="Save Settings">
                </p>
            </form>

    

            

            <!-- Shortcodes Section -->
            <div style="margin-top: 30px;">
                <h3>Available Shortcodes</h3>
                <table class="widefat" style="margin-top: 10px;">
                    <thead>
                        <tr>
                            <th>Shortcode</th>
                            <th>Description</th>
                            <th>Example Usage</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><code>[newsletter_list]</code></td>
                            <td>Displays a list of all entries</td>
                            <td><code>[newsletter_list order="ASC"]</code></td>
                        </tr>
                        <tr>
                            <td><code>[newsletter_term]</code></td>
                            <td>Shows entries for a specific term</td>
                            <td><code>[newsletter_term term="1" order="DESC"]</code></td>
                        </tr>
                        <tr>
                            <td><code>[newsletter_week]</code></td>
                            <td>Shows entries for a specific week</td>
                            <td><code>[newsletter_week week="1" order="DESC"]</code></td>
                        </tr>
                        <tr>
                            <td><code>[newsletter_latest]</code></td>
                            <td>Shows the most recent entries</td>
                            <td><code>[newsletter_latest limit="5" order="DESC"]</code></td>
                        </tr>
                        <tr>
                            <td><code>[newsletter_archive]</code></td>
                            <td>Shows archived entries</td>
                            <td><code>[newsletter_archive year="2023" order="DESC"]</code></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Clear Database Section -->
            <div style="margin-top: 30px;">
                <h3>Database Management</h3>
                <form method="post" action="" onsubmit="return confirm('WARNING: This will delete all entries. Are you sure you want to proceed?');">
                    <?php wp_nonce_field('clear_database_action', 'clear_database_nonce'); ?>
                    <p class="description">Warning: This action cannot be undone.</p>
                    <p class="submit">
                        <input type="submit" name="clear_database" class="button button-link-delete" value="Clear Database">
                    </p>
                </form>
            </div>

            <!-- Database Overview Section -->
            <div style="margin-top: 30px; padding: 20px; background: #f8f9fa; border: 1px solid #ddd;">
                <h3>Debug Information (Admin Only)</h3>
                
                <!-- Active Newsletters Database -->
                <h4>Table Name: <?php echo $wpdb->prefix; ?>newsletter_manager_entries</h4>
                <pre style="background: #f0f0f0; padding: 15px; overflow: auto;">
<?php
$table_name = $wpdb->prefix . 'newsletter_manager_entries';
$active_entries = $wpdb->get_results("SELECT * FROM $table_name ORDER BY id ASC", ARRAY_A);
print_r($active_entries);
?>
                </pre>

                <!-- Archived Newsletters Database -->
                <h4>Table Name: <?php echo $wpdb->prefix; ?>newsletter_manager_entries_archive</h4>
                <pre style="background: #f0f0f0; padding: 15px; overflow: auto;">
<?php
$archive_table_name = $wpdb->prefix . 'newsletter_manager_entries_archive';
$archived_entries = $wpdb->get_results("SELECT * FROM $archive_table_name ORDER BY id ASC", ARRAY_A);
print_r($archived_entries);
?>
                </pre>
            </div>

            <!-- Debug Archive Function -->
            <div style="margin-top: 30px; padding: 15px; background-color: #f9f9f9; border: 1px solid #ddd;">
                <h3>Archive Troubleshooting</h3>
                <form method="post" action="">
                    <?php wp_nonce_field('debug_archive_action', 'debug_archive_nonce'); ?>
                    <p class="description">
                        <strong>Warning:</strong> This is a diagnostic tool to help understand why archive operations aren't working.
                    </p>
                    <p class="submit">
                        <input type="submit" 
                               name="debug_archive" 
                               class="button" 
                               value="Run Archive Diagnostics">
                    </p>
                </form>
            </div>
        </div>
    </div>
    <?php

    // Handle admin settings form submission
    if (isset($_POST['save_admin_settings'])) {
        if (!isset($_POST['admin_settings_nonce']) || !wp_verify_nonce($_POST['admin_settings_nonce'], 'admin_settings_action')) {
            wp_die('Security check failed');
        }
        
        // Save display name format
        if (isset($_POST['display_name_format'])) {
            update_option('newsletter_manager_display_name_format', sanitize_text_field($_POST['display_name_format']));
        }
        
        // Save auto archive setting
        $auto_archive = isset($_POST['auto_archive_enabled']) ? '1' : '0';
        update_option('newsletter_manager_auto_archive', $auto_archive);
        
        echo '<div class="notice notice-success is-dismissible"><p>Settings saved successfully!</p></div>';
    }

    // Handle archive old entries
    if (isset($_POST['archive_old_entries'])) {
        if (!isset($_POST['archive_nonce']) || !wp_verify_nonce($_POST['archive_nonce'], 'archive_entries_action')) {
            wp_die('Security check failed');
        }
        
        // Call the auto archive function to process the archiving
        $archived_count = newsletter_manager_process_archiving();
        
        if ($archived_count > 0) {
            echo '<div class="notice notice-success is-dismissible"><p>' . $archived_count . ' newsletters have been moved to the archive.</p></div>';
        } else {
            echo '<div class="notice notice-info is-dismissible"><p>No newsletters from previous years found to archive.</p></div>';
        }
    }

    // Handle archive specific year
    if (isset($_POST['archive_specific_year'])) {
        if (!isset($_POST['archive_specific_year_nonce']) || 
            !wp_verify_nonce($_POST['archive_specific_year_nonce'], 'archive_specific_year_action')) {
            wp_die('Security check failed');
        }
        
        $year_to_archive = isset($_POST['year_to_archive']) ? intval($_POST['year_to_archive']) : 0;
        
        if ($year_to_archive > 0) {
            $archived_count = newsletter_manager_archive_specific_year($year_to_archive);
            
            if ($archived_count > 0) {
                echo '<div class="notice notice-success is-dismissible"><p>' . $archived_count . ' newsletters from ' . $year_to_archive . ' have been moved to the archive.</p></div>';
            } else {
                echo '<div class="notice notice-info is-dismissible"><p>No newsletters found from ' . $year_to_archive . ' to archive.</p></div>';
            }
        }
    }
}

// Initialize plugin
function newsletter_manager_init() {
    // Add initialization code here
}
add_action('init', 'newsletter_manager_init');

// Add shortcode functions
add_shortcode('newsletter_list', 'newsletter_manager_list_shortcode');
add_shortcode('newsletter_term', 'newsletter_manager_term_shortcode');
add_shortcode('newsletter_week', 'newsletter_manager_week_shortcode');
add_shortcode('newsletter_latest', 'newsletter_manager_latest_shortcode');
add_shortcode('newsletter_archive', 'newsletter_manager_archive_shortcode');

// Display all entries
function newsletter_manager_list_shortcode($atts) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'newsletter_manager_entries';
    
    // Parse attributes
    $args = shortcode_atts(array(
        'term' => '',
        'week' => '',
        'order' => 'ASC'     // Default ascending order
    ), $atts);
    
    // Build query
    $query = "SELECT * FROM $table_name WHERE 1=1";
    if (!empty($args['term'])) {
        $query .= $wpdb->prepare(" AND term = %d", intval($args['term']));
    }
    if (!empty($args['week'])) {
        $query .= $wpdb->prepare(" AND week = %d", intval($args['week']));
    }

    // Handle ordering
    $order = strtoupper($args['order']) === 'DESC' ? 'DESC' : 'ASC';
    $query .= " ORDER BY term $order, week $order";
    
    $entries = $wpdb->get_results($query);
    $upload_dir = newsletter_manager_get_upload_dir();
    
    $output = '<div class="newsletter_manager-entries">';
    
    if (!empty($entries)) {
        $output .= '<ul style="list-style: none; padding-left: 0;">';
        foreach ($entries as $entry) {
            $file_url = $upload_dir['url'] . '/' . $entry->file_name;
            $output .= sprintf(
                '<li style="margin-bottom: 10px;"><a href="%s" target="_blank">%s</a></li>',
                esc_url($file_url),
                esc_html($entry->display_name)
            );
        }
        $output .= '</ul>';
    } else {
        $output .= '<p>No entries found.</p>';
    }
    
    $output .= '</div>';
    return $output;
}

// Display entries for specific term
function newsletter_manager_term_shortcode($atts) {
    $args = shortcode_atts(array(
        'term' => '1',
        'order' => 'ASC'
    ), $atts);
    
    return newsletter_manager_list_shortcode(array(
        'term' => $args['term'],
        'order' => $args['order']
    ));
}

// Display entries for specific week
function newsletter_manager_week_shortcode($atts) {
    $args = shortcode_atts(array(
        'week' => '1',
        'order' => 'ASC'
    ), $atts);
    
    return newsletter_manager_list_shortcode(array(
        'week' => $args['week'],
        'order' => $args['order']
    ));
}

// Display latest entries
function newsletter_manager_latest_shortcode($atts) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'newsletter_manager_entries';
    
    $args = shortcode_atts(array(
        'limit' => '5',
        'order' => 'DESC'
    ), $atts);
    
    $limit = intval($args['limit']);
    $order = strtoupper($args['order']) === 'DESC' ? 'DESC' : 'ASC';
    
    $entries = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table_name ORDER BY term $order, week $order LIMIT %d",
        $limit
    ));
    
    $upload_dir = newsletter_manager_get_upload_dir();
    
    $output = '<div class="newsletter_manager-entries">';
    
    if (!empty($entries)) {
        $output .= '<ul style="list-style: none; padding-left: 0;">';
        foreach ($entries as $entry) {
            $file_url = $upload_dir['url'] . '/' . $entry->file_name;
            $output .= sprintf(
                '<li style="margin-bottom: 10px;"><a href="%s" target="_blank">%s</a></li>',
                esc_url($file_url),
                esc_html($entry->display_name)
            );
        }
        $output .= '</ul>';
    } else {
        $output .= '<p>No entries found.</p>';
    }
    
    $output .= '</div>';
    return $output;
}

// Display archived entries with hierarchical structure and dropdowns
function newsletter_manager_archive_shortcode($atts) {
    global $wpdb;
    $archive_table_name = $wpdb->prefix . 'newsletter_manager_entries_archive';
    
    $args = shortcode_atts(array(
        'year' => '',
        'order' => 'DESC'
    ), $atts);
    
    $query = "SELECT * FROM $archive_table_name WHERE 1=1";
    if (!empty($args['year'])) {
        $query .= $wpdb->prepare(" AND year = %d", intval($args['year']));
    }
    
    $order = strtoupper($args['order']) === 'DESC' ? 'DESC' : 'ASC';
    $query .= " ORDER BY year $order, term $order, week $order";
    
    $entries = $wpdb->get_results($query);
    $upload_dir = newsletter_manager_get_upload_dir();
    
    // Add the required CSS for dropdowns
    $output = '
    <style>
        .archive-dropdown {
            margin-bottom: 10px;
        }
        .archive-dropdown summary {
            cursor: pointer;
            padding: 10px;
            background-color: #f0f0f0;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        .archive-dropdown summary:hover {
            background-color: #e0e0e0;
        }
        .year-dropdown {
            margin-left: 20px;
            margin-bottom: 10px;
        }
        .term-section {
            margin-left: 40px;
            margin-bottom: 10px;
        }
        .archive-entries {
            list-style: none;
            padding-left: 20px;
        }
    </style>';
    
    $output .= '<div class="newsletter_manager-archive-entries">';
    
    if (!empty($entries)) {
        // Main Archive dropdown
        $output .= '<details class="archive-dropdown">';
        $output .= '<summary><strong>Archive</strong></summary>';
        
        // Group entries by year and term
        $grouped_entries = array();
        foreach ($entries as $entry) {
            $grouped_entries[$entry->year][$entry->term][] = $entry;
        }
        
        // Sort years in descending order
        krsort($grouped_entries);
        
        foreach ($grouped_entries as $year => $terms) {
            // Year dropdown
            $output .= '<details class="year-dropdown">';
            $output .= sprintf('<summary><strong>%d</strong></summary>', $year);
            
            // Sort terms
            ksort($terms);
            
            foreach ($terms as $term => $term_entries) {
                $output .= '<div class="term-section">';
                $output .= sprintf('<h4>Term %d</h4>', $term);
                $output .= '<ul class="archive-entries">';
                
                // Sort entries by week
                usort($term_entries, function($a, $b) {
                    return $a->week - $b->week;
                });
                
                foreach ($term_entries as $entry) {
                    $file_url = $upload_dir['url'] . '/' . $entry->file_name;
                    $output .= sprintf(
                        '<li><a href="%s" target="_blank">%s</a></li>',
                        esc_url($file_url),
                        esc_html($entry->display_name)
                    );
                }
                $output .= '</ul>';
                $output .= '</div>';
            }
            $output .= '</details>'; // Close year dropdown
        }
        $output .= '</details>'; // Close main archive dropdown
    } else {
        $output .= '<p>No archived entries found.</p>';
    }
    
    $output .= '</div>';
    return $output;
}

// Create the archive page content
function newsletter_manager_archive_page() {
    if (!current_user_can('manage_options')) {
        wp_die('You do not have sufficient permissions to access this page.');
    }

    global $wpdb;
    $archive_table_name = $wpdb->prefix . 'newsletter_manager_entries_archive';

    // Handle delete action
    if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
        $id = intval($_GET['id']);
        if (isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'delete_archive_entry_' . $id)) {
            // Get file name before deleting the entry
            $entry = $wpdb->get_row($wpdb->prepare("SELECT file_name FROM $archive_table_name WHERE id = %d", $id));
            
            if ($entry) {
                // Delete the file
                $upload_dir = newsletter_manager_get_upload_dir();
                $file_path = $upload_dir['path'] . '/' . $entry->file_name;
                if (file_exists($file_path)) {
                    unlink($file_path);
                }
                
                // Delete the database entry
                $wpdb->delete(
                    $archive_table_name,
                    array('id' => $id),
                    array('%d')
                );
                
                echo '<div class="notice notice-success is-dismissible"><p>Archived newsletter deleted successfully!</p></div>';
            }
        } else {
            echo '<div class="notice notice-error is-dismissible"><p>Security check failed.</p></div>';
        }
    }

    // Handle update submission
    if (isset($_POST['update_archive_entry'])) {
        if (!isset($_POST['update_archive_nonce']) || 
            !wp_verify_nonce($_POST['update_archive_nonce'], 'update_archive_action')) {
            wp_die('Security check failed');
        }

        $id = intval($_POST['entry_id']);
        $display_name = sanitize_text_field($_POST['display_name']);
        $week = intval($_POST['week']);
        $term = intval($_POST['term']);
        $year = intval($_POST['year']);

        $update_data = array(
            'display_name' => $display_name,
            'week' => $week,
            'term' => $term,
            'year' => $year
        );
        $update_format = array('%s', '%d', '%d', '%d');

        $upload_dir = newsletter_manager_get_upload_dir();
        
        // Get current file information
        $current_entry = $wpdb->get_row($wpdb->prepare("SELECT file_name FROM $archive_table_name WHERE id = %d", $id));
        $old_file_path = $upload_dir['path'] . '/' . $current_entry->file_name;
        $file_extension = pathinfo($current_entry->file_name, PATHINFO_EXTENSION);

        // Handle file upload if a new file was provided (this takes precedence over renaming)
        if (!empty($_FILES['newsletter_file']['name'])) {
            $file = $_FILES['newsletter_file'];
            
            // Upload new file
            $file_info = pathinfo($file['name']);
            $new_filename = sanitize_file_name($file_info['filename'] . '.' . $file_info['extension']);
            
            if (move_uploaded_file($file['tmp_name'], $upload_dir['path'] . '/' . $new_filename)) {
                // Delete old file
                if (file_exists($old_file_path)) {
                    unlink($old_file_path);
                }
                
                // Update database with new filename
                $update_data['file_name'] = $new_filename;
                $update_format[] = '%s';
            } else {
                echo '<div class="notice notice-error is-dismissible"><p>Error uploading file.</p></div>';
                return;
            }
        }
        // Handle file renaming if a new filename was provided and no file upload
        else if (!empty($_POST['new_filename'])) {
            $new_filename_base = sanitize_file_name($_POST['new_filename']);
            $new_filename = $new_filename_base . '.' . $file_extension;
            $new_file_path = $upload_dir['path'] . '/' . $new_filename;
            
            // Check if target filename already exists
            if (file_exists($new_file_path) && $new_filename !== $current_entry->file_name) {
                echo '<div class="notice notice-error is-dismissible"><p>A file with this name already exists. Please choose a different name.</p></div>';
                return;
            }
            
            // Rename the file
            if (rename($old_file_path, $new_file_path)) {
                // Update database with new filename
                $update_data['file_name'] = $new_filename;
                $update_format[] = '%s';
            } else {
                echo '<div class="notice notice-error is-dismissible"><p>Error renaming file.</p></div>';
                return;
            }
        }

        // Update database
        $result = $wpdb->update(
            $archive_table_name,
            $update_data,
            array('id' => $id),
            $update_format,
            array('%d')
        );

        if ($result !== false) {
            echo '<div class="notice notice-success is-dismissible"><p>Archived newsletter updated successfully!</p></div>';
        } else {
            echo '<div class="notice notice-error is-dismissible"><p>Error updating archived newsletter.</p></div>';
        }
    }
    
    // Handle restore action (move from archive back to main table)
    if (isset($_GET['action']) && $_GET['action'] === 'restore' && isset($_GET['id'])) {
        $id = intval($_GET['id']);
        if (isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'restore_archive_entry_' . $id)) {
            $table_name = $wpdb->prefix . 'newsletter_manager_entries';
            
            // Get the archive entry
            $entry = $wpdb->get_row($wpdb->prepare("SELECT * FROM $archive_table_name WHERE id = %d", $id));
            
            if ($entry) {
                // Insert into main table
                $result = $wpdb->insert(
                    $table_name,
                    array(
                        'display_name' => $entry->display_name,
                        'file_name' => $entry->file_name,
                        'week' => $entry->week,
                        'term' => $entry->term,
                        'year' => $entry->year,
                        'created_at' => $entry->created_at
                    ),
                    array('%s', '%s', '%d', '%d', '%d', '%s')
                );
                
                if ($result) {
                    // Delete from archive table
                    $wpdb->delete(
                        $archive_table_name,
                        array('id' => $id),
                        array('%d')
                    );
                    
                    echo '<div class="notice notice-success is-dismissible"><p>Newsletter restored from archive successfully!</p></div>';
                } else {
                    echo '<div class="notice notice-error is-dismissible"><p>Error restoring newsletter from archive.</p></div>';
                }
            }
        } else {
            echo '<div class="notice notice-error is-dismissible"><p>Security check failed.</p></div>';
        }
    }
    
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th scope="col">Display Name</th>
                    <th scope="col">File Name</th>
                    <th scope="col">Week</th>
                    <th scope="col">Term</th>
                    <th scope="col">Year</th>
                    <th scope="col">Archived Date</th>
                    <th scope="col">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $entries = $wpdb->get_results("SELECT * FROM $archive_table_name ORDER BY year DESC, term ASC, week ASC");
                foreach ($entries as $entry) :
                    $delete_nonce = wp_create_nonce('delete_archive_entry_' . $entry->id);
                    $restore_nonce = wp_create_nonce('restore_archive_entry_' . $entry->id);
                    $edit_nonce = wp_create_nonce('edit_archive_entry_' . $entry->id);
                    ?>
                    <tr id="archive-row-<?php echo $entry->id; ?>">
                        <td><?php echo esc_html($entry->display_name); ?></td>
                        <td><?php echo esc_html($entry->file_name); ?></td>
                        <td>Week <?php echo esc_html($entry->week); ?></td>
                        <td>Term <?php echo esc_html($entry->term); ?></td>
                        <td><?php echo esc_html($entry->year); ?></td>
                        <td><?php echo esc_html(date('Y-m-d', strtotime($entry->archived_at))); ?></td>
                        <td>
                            <a href="#" class="button button-small" onclick="toggleArchiveEditForm(<?php echo $entry->id; ?>); return false;">Edit</a>
                            <a href="?page=newsletter-manager-archive&action=restore&id=<?php echo $entry->id; ?>&_wpnonce=<?php echo $restore_nonce; ?>" 
                               class="button button-small">Restore</a>
                            <a href="?page=newsletter-manager-archive&action=delete&id=<?php echo $entry->id; ?>&_wpnonce=<?php echo $delete_nonce; ?>" 
                               class="button button-small button-link-delete" 
                               onclick="return confirm('Are you sure you want to delete this archived entry?');">Delete</a>
                        </td>
                    </tr>
                    <!-- Add inline edit form row (hidden by default) -->
                    <tr id="archive-edit-row-<?php echo $entry->id; ?>" class="archive-edit-form-row" style="display: none;">
                        <td colspan="7" style="padding: 15px; background-color: #f9f9f9; border-bottom: 1px solid #ddd;">
                            <form method="post" enctype="multipart/form-data">
                                <?php wp_nonce_field('update_archive_action', 'update_archive_nonce'); ?>
                                <input type="hidden" name="entry_id" value="<?php echo $entry->id; ?>">
                                <table class="form-table">
                                    <tr>
                                        <th scope="row">Current File</th>
                                        <td>
                                            <?php echo esc_html($entry->file_name); ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Rename File</th>
                                        <td>
                                            <input type="text" 
                                                   name="new_filename" 
                                                   class="regular-text" 
                                                   placeholder="New filename (without extension)">
                                            <p class="description">Leave blank to keep the current filename. Enter only the name without .pdf extension.</p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Or Replace File</th>
                                        <td>
                                            <input type="file" name="newsletter_file" accept=".pdf">
                                            <p class="description">Upload a new file to replace the current one. This will override the rename option above.</p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Week</th>
                                        <td>
                                            <select name="week" required>
                                                <?php for ($i = 1; $i <= 11; $i++) : ?>
                                                    <option value="<?php echo $i; ?>" <?php selected($entry->week, $i); ?>>Week <?php echo $i; ?></option>
                                                <?php endfor; ?>
                                            </select>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Term</th>
                                        <td>
                                            <select name="term" required>
                                                <?php for ($i = 1; $i <= 4; $i++) : ?>
                                                    <option value="<?php echo $i; ?>" <?php selected($entry->term, $i); ?>>Term <?php echo $i; ?></option>
                                                <?php endfor; ?>
                                            </select>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Year</th>
                                        <td>
                                            <select name="year" required>
                                                <?php 
                                                $current_year = date('Y');
                                                for ($i = $current_year; $i >= $current_year - 5; $i--) : 
                                                ?>
                                                    <option value="<?php echo $i; ?>" <?php selected($entry->year, $i); ?>><?php echo $i; ?></option>
                                                <?php endfor; ?>
                                            </select>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Display Name</th>
                                        <td>
                                            <input type="text" name="display_name" class="regular-text" required value="<?php echo esc_attr($entry->display_name); ?>">
                                        </td>
                                    </tr>
                                </table>
                                <p class="submit">
                                    <input type="submit" name="update_archive_entry" class="button button-primary" value="Update Newsletter">
                                    <button type="button" class="button" onclick="toggleArchiveEditForm(<?php echo $entry->id; ?>)">Cancel</button>
                                </p>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($entries)) : ?>
                    <tr>
                        <td colspan="7">No archived entries found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
        
        <!-- Add JavaScript for toggling archive edit forms -->
        <script>
            function toggleArchiveEditForm(id) {
                var editRow = document.getElementById('archive-edit-row-' + id);
                if (editRow.style.display === 'none' || editRow.style.display === '') {
                    // Hide any other open edit forms first
                    var allEditRows = document.getElementsByClassName('archive-edit-form-row');
                    for (var i = 0; i < allEditRows.length; i++) {
                        allEditRows[i].style.display = 'none';
                    }
                    // Show this edit form
                    editRow.style.display = 'table-row';
                    
                    // Optional: highlight the row being edited
                    document.getElementById('archive-row-' + id).style.backgroundColor = '#f0f7ff';
                } else {
                    // Hide this edit form
                    editRow.style.display = 'none';
                    
                    // Remove highlight
                    document.getElementById('archive-row-' + id).style.backgroundColor = '';
                }
            }
        </script>
    </div>
    <?php
}

// Update the existing auto-archive function
function newsletter_manager_auto_archive() {
    if (get_option('newsletter_manager_auto_archive', '0') !== '1') {
        return;
    }

    // Use the shared archiving function
    newsletter_manager_process_archiving();
}

// Add the auto-archive check to run daily
if (!wp_next_scheduled('newsletter_manager_daily_archive_check')) {
    wp_schedule_event(time(), 'daily', 'newsletter_manager_daily_archive_check');
}
add_action('newsletter_manager_daily_archive_check', 'newsletter_manager_auto_archive');

// Function to process archiving (for both manual and auto archiving)
function newsletter_manager_process_archiving() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'newsletter_manager_entries';
    $archive_table_name = $wpdb->prefix . 'newsletter_manager_entries_archive';
    $current_year = intval(date('Y'));
    $archived_count = 0;

    // Get all entries where the year field is less than current year
    // This ignores created_at date completely
    $old_entries = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM $table_name WHERE year < %d",
            $current_year
        )
    );

    foreach ($old_entries as $entry) {
        // Insert into archive table
        $result = $wpdb->insert(
            $archive_table_name,
            array(
                'display_name' => $entry->display_name,
                'file_name' => $entry->file_name,
                'week' => $entry->week,
                'term' => $entry->term,
                'year' => $entry->year,
                'created_at' => $entry->created_at,
                'archived_at' => current_time('mysql')
            ),
            array('%s', '%s', '%d', '%d', '%d', '%s', '%s')
        );

        if ($result) {
            // Delete from main table
            $wpdb->delete(
                $table_name,
                array('id' => $entry->id),
                array('%d')
            );
            $archived_count++;
        }
    }
    
    return $archived_count;
}

// Function to directly archive entries from a specific year
function newsletter_manager_archive_specific_year($year_to_archive) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'newsletter_manager_entries';
    $archive_table_name = $wpdb->prefix . 'newsletter_manager_entries_archive';
    $archived_count = 0;
    
    // Cast to integer to ensure proper comparison
    $year_to_archive = intval($year_to_archive);
    
    // Get all entries with the exact year specified
    $query = $wpdb->prepare("SELECT * FROM $table_name WHERE year = %d", $year_to_archive);
    $entries_to_archive = $wpdb->get_results($query);
    
    if (empty($entries_to_archive)) {
        return 0; // No entries found with this year
    }
    
    foreach ($entries_to_archive as $entry) {
        // Insert into archive table
        $result = $wpdb->insert(
            $archive_table_name,
            array(
                'display_name' => $entry->display_name,
                'file_name' => $entry->file_name,
                'week' => intval($entry->week),  // Ensure integer
                'term' => intval($entry->term),  // Ensure integer
                'year' => intval($entry->year),  // Ensure integer
                'created_at' => $entry->created_at,
                'archived_at' => current_time('mysql')
            ),
            array('%s', '%s', '%d', '%d', '%d', '%s', '%s')
        );
        
        if ($result) {
            // Delete from main table
            $wpdb->delete(
                $table_name,
                array('id' => $entry->id),
                array('%d')
            );
            $archived_count++;
        }
    }
    
    return $archived_count;
}
