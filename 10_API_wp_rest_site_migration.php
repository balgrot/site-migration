<?php

/*
 * Plugin Name: WP REST API Site migration between networks
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

// set up an endpoint to dump site's tables on an sql file
add_action( 'rest_api_init', function () {
  register_rest_route( 'api/v1', '/migration/export', array(
    'methods' => 'POST',
    'callback' => 'export_site',
    'show_in_index' => false,
    'middleware' => ['default', 'network'],
    'permission_callback' => 'rest_api_middleware',
    ) );
  } );

function export_site( WP_REST_Request $request ){

    $blog_id = $request['blog_id'];

    if(empty($blog_id)) {
        $result = array(
            'success' => false,
            'result' => "Blog ID is required.",
        );
        return new WP_REST_Response( $result, 200 );
    }

    $title = get_blog_option( $blog_id, 'blogname');
    $blog_details = get_blog_details($blog_id);
    if(empty($blog_details)) {
        $result = array(
            'success' => false,
            'result' => "Blog with that ID does not exist.",
        );
        return new WP_REST_Response( $result, 200 );
    }

    if(!file_exists(WP_CONTENT_DIR   . '/uploads/migration/export')) {
        wp_mkdir_p(WP_CONTENT_DIR   . '/uploads/migration/export');
    }

    $file_name = 'db_export_' . $blog_id . '.txt';
    $file_name_full_path = WP_CONTENT_DIR   . '/uploads/migration/export/' . $file_name;

    //Site_Helper::export_database(  );
    Site_Helper::backup_tables( $blog_id, $file_name_full_path );

    $new_file_size = filesize($file_name_full_path);

    if($new_file_size > 0) {

        global $wpdb;
        $mapped_domain = '';

        $mapped = $wpdb->get_row("SELECT blog_id, domain FROM wp_domain_mapping WHERE blog_id = $blog_id");
        if (null != $mapped) {
            $mapped_domain = $mapped->domain;
        }

        // Export users
        $user_query = new WP_User_Query( array( 'blog_id' => $blog_id, 'role__in' => array('editor') ));
        $users = $user_query->get_results();

        $user_meta = [];
        foreach($users as $user) {
            $user_meta[$user->ID] = get_user_meta( $user->ID );
        }

        $url = WP_CONTENT_URL . '/uploads/migration/export/' . $file_name;
        $result = array(
            'success'      => true,
            'result' => [
                'url'            => $url,
                'file_name'      => $file_name,
                'title'          => $title,
                'path'           => $blog_details->path,
                'mapped_domain'  => $mapped_domain,
                'old_cloudfront' => AWS_CLOUDFRONT . '/sites/' . $blog_id,
                'old_bucket'     => S3_IMAGE_BUCKET,
                'old_install'     => AE_INSTALL,
                'bucket_prefix'  => 'sites/' . $blog_id,
                'remote_blog_meta'      => get_site_meta($blog_id),
                'users'          => $users,
                'user_meta'      => $user_meta,
                'theme_mods'      => serialize(get_blog_option($blog_id, 'theme_mods_monterey')),
            ]
        );
        return new WP_REST_Response( $result, 200 );
        
    } else {
        $result = array(
            'success' => false,
            'result' => 'DB size was zero, probably did not work.',
        );
        return new WP_REST_Response( $result, 200 );
    }

  $result = array(
        'success' => false,
        'result' => "Something unknown happened.",
    );
    return new WP_REST_Response( $result, 200 );
}

// set up an endpoint to dump site's tables on an sql file
add_action( 'rest_api_init', function () {
  register_rest_route( 'api/v1', '/migration/delexport', array(
    'methods' => 'POST',
    'callback' => 'rest_delete_export_file',
    'show_in_index' => false,
    'middleware' => ['default', 'network'],
    'permission_callback' => 'rest_api_middleware',
    //'permission_callback' => '__return_true',
    ) );
  } );

function rest_delete_export_file( WP_REST_Request $request ){

  $file_name = $request['file_name'];

    if(empty($file_name)) {
        $result = array(
            'success'      => false,
            'result'         => 'File name was missing.',
        );
        return new WP_REST_Response( $result, 200 );
    }

  $file_name_full_path = WP_CONTENT_DIR   . '/uploads/migration/export/' . $file_name;
  
  if( unlink( $file_name_full_path ) ){
    $result = array(
      'success'      => true,
      'result'         => 'Export file deleted successfully',
    );
    return new WP_REST_Response( $result, 200 );
  }
  else{
      $result = array(
        'success'      => false,
        'result'         => 'Export could not be deleted',
    );
    return new WP_REST_Response( $result, 200 );
  }
}

add_action( 'wp_ajax_import_remote_site', 'import_remote_site' );

function import_remote_site(){

    global $wpdb;
    check_ajax_referer( 'admin_nonce', 'security' );

    if( isset( $_POST ) ) {

        $remote_server_url = $_POST['remote_server_url'];
        $remote_site_id = $_POST['remote_site_id'];

        /*
        * Tell the remote site to export the tables. It will provide with a download url
        */
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $remote_server_url . 'api/v1/migration/export',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => json_encode(array('blog_id' => $remote_site_id)),
            CURLOPT_HTTPHEADER => array(
            'Multisite-Key: ' . REST_API_TOKEN,
            'Content-Type: application/json'
            ),
        ));

        $response = curl_exec($curl);
        curl_close($curl);

        $response_body = json_decode($response);

        if( $response_body->success == true){
            $file_name = $response_body->result->file_name;
            $download_url = $response_body->result->url;
            $network = DOMAIN_CURRENT_SITE;
            $sa_id = ms_create_superadmin_user();
            $title = $response_body->result->title;
            $path = Multisite_Helper::clean(trim(str_replace('/', '', $response_body->result->path)));
            $mapped_domain = $response_body->result->mapped_domain;
            $remote_users = $response_body->result->users;
            $user_meta = $response_body->result->user_meta;
            $old_cloudfront = $response_body->result->old_cloudfront;
            $old_bucket = $response_body->result->old_bucket;
            $old_install = $response_body->result->old_install;
            $bucket_prefix = $response_body->result->bucket_prefix;
            $remote_blog_meta = $response_body->result->remote_blog_meta;
            $theme_mods = $response_body->result->theme_mods;
            //write_log($path);

            /*
            * Download the sql script.
            */
            $args = array(
                'headers' => array(
                  'Multisite-Key' => REST_API_TOKEN,
            ));
            $response = wp_remote_get( $download_url, $args );
            if(!is_wp_error($response) && !empty($response) && 200 == $response['response']['code'] ) {
                $remote_sql = $response['body'];
                //write_log($response);
            } else {
                echo json_encode(array('error' => 'Can\'t download the file.'));
                wp_die();
            }

            $curl = curl_init();
            curl_setopt_array($curl, array(
                CURLOPT_URL => $remote_server_url . 'api/v1/migration/delexport',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => json_encode(array('file_name' => $file_name)),
                CURLOPT_HTTPHEADER => array(
                    'Multisite-Key: ' . REST_API_TOKEN,
                    'Content-Type: application/json'
                ),
            ));

            $delete_response = curl_exec($curl);
            curl_close($curl);
            //write_log($delete_response);

            //Create new site
            $new_blog_id = wpmu_create_blog( $network, '/' . $path, $title, $sa_id );

            //write_log($new_blog_id);

            if( is_wp_error( $new_blog_id ) ) {
                echo json_encode(array(
                    'errors' => 'ERROR: ' . $new_blog_id->get_error_message()
                ));
                wp_die();
            }

            $new_blog_details = get_blog_details($new_blog_id);

            if(!file_exists(WP_CONTENT_DIR   . '/uploads/migration/import')) {
                wp_mkdir_p(WP_CONTENT_DIR   . '/uploads/migration/import');
            }

            foreach($remote_blog_meta as $key => $value) {

                if(stripos($key, 'db_') !== false) {
                    continue;
                }

                if(is_serialized( $value[0] )) {

                    $new_value = maybe_unserialize($value[0]);

                    update_site_meta($new_blog_id, $key, $new_value);

                } else {

                    update_site_meta($new_blog_id, $key, $value[0]);

                }
 
            }

            $prefix = $wpdb->base_prefix;
            $old_prefix = $prefix . $remote_site_id;
            $new_prefix = $prefix . $new_blog_id;

            $local_sql = str_replace($old_prefix, $new_prefix, $remote_sql);

            // Replace old url for new url in options table.
            $old_url = str_replace( 'wp-json/', '', $remote_server_url) . $path;
            $new_url = 'https://' . DOMAIN_CURRENT_SITE . '/' . $path;
            $local_sql = str_replace( $old_url, $new_url, $local_sql);

            $new_file_name = WP_CONTENT_DIR . '/uploads/migration/import/db_import_' . $new_blog_id . '.txt';
            file_put_contents($new_file_name, $local_sql);

            $host = DB_HOST;
            $db   = DB_NAME;
            $user = DB_USER;
            $pass = DB_PASSWORD;
            $port = "3306";
            $charset = 'utf8mb4';

            $dsn = "mysql:host=$host;dbname=$db;charset=$charset;port=$port";
            try {
                $db = new \PDO($dsn, $user, $pass);
            } catch (\PDOException $e) {
                throw new \PDOException($e->getMessage(), (int)$e->getCode());
            }

            $qr = $db->exec($local_sql);

            sleep(5);

            // Replace remote bucket with new bucket
            $prefix = get_site_s3_prefix();

            $s3_url_1 = "https://" . $old_bucket . ".s3.amazonaws.com/" . $bucket_prefix;
            Site_Helper::search_and_replace_db($new_blog_id, $s3_url_1, AWS_CLOUDFRONT . '/' . $prefix . $new_blog_id);

            $s3_url_2 = "https://" . $old_bucket . ".s3.us-west-2.amazonaws.com/" . $bucket_prefix;
            Site_Helper::search_and_replace_db($new_blog_id, $s3_url_2, AWS_CLOUDFRONT . '/' . $prefix . $new_blog_id);

            Site_Helper::search_and_replace_db($new_blog_id, AWS_URL, AWS_CLOUDFRONT);

            Site_Helper::search_and_replace_db($new_blog_id, "https://" . S3_IMAGE_BUCKET . ".s3.us-west-2.amazonaws.com", AWS_CLOUDFRONT);

            Site_Helper::search_and_replace_db($new_blog_id, $old_cloudfront, AWS_CLOUDFRONT . '/' . $prefix . $new_blog_id);

            Site_Helper::search_and_replace_db($new_blog_id, $bucket_prefix, $prefix . $new_blog_id);

            Site_Helper::search_and_replace_db($new_blog_id, $old_bucket, S3_IMAGE_BUCKET);

            unlink($new_file_name);

            $mainUser = get_option('main-user');

            // Import the remote users
            foreach ( $remote_users as $user) {
                $user_id = username_exists( $user->data->user_login );
                if ( ! $user_id && false == email_exists( $user->data->user_email ) ) {
                    $user_id = wp_create_user( $user->data->user_login, $user->data->user_pass, $user->data->user_email );
                }

                $old_user_id = $user->data->ID;

                if($old_user_id == $mainUser) {
                    update_option('main-user', $user_id);
                }

                $export_user_meta = $user_meta->$old_user_id;

                update_user_meta( $user_id, 'primary_blog', $new_blog_id );

                foreach($export_user_meta as $key => $value) {

                    if(is_serialized( $value[0] )) {

                        $new_value = maybe_unserialize($value[0]);

                        update_user_meta( $user_id, $key, $new_value );

                    } else {

                        if(stripos($key, "wp_" . $remote_site_id) !== false) {

                            $key = str_replace("wp_" . $remote_site_id, "wp_" . $new_blog_id, $key);
                    
                        }

                        if(stripos($key, "_user_level") !== false || stripos($key, "_capabilities") !== false) {

                            continue;
                    
                        }

                        if($key == "login_current_" . $old_user_id) {

                            $key = "login_current_" . $user_id;
                    
                        }

                        update_user_meta( $user_id, $key, $value[0] );

                    }

                }

                add_user_to_blog( $new_blog_id, $user_id, 'editor' );
            }

            if(PRODUCTION) {

                $test_site_object = [
                    'Account__c' => get_blog_option( $new_blog_id, 'salesforce_account_id'),
                    'Active__c' => 1,
                    'Multisite_ID__c' => $new_blog_id,
                    'Multisite_Install__c' => AE_INSTALL,
                    'Migration_Domain__c' => $mapped_domain,
                    'Live_URL__c' => Multisite::get_test_url($new_blog_id),
                    'Name' => Multisite::get_test_url($new_blog_id),
                ];

                $result = Salesforce::curl_wrap('Websites__c', 'POST', $test_site_object, '');
                
            }

            echo json_encode(array('success' => 'Site imported successfully!'));
            wp_die();

        } else {
            echo json_encode(array('error' => $response_body));
            wp_die();
        }

        echo json_encode(array('error' => 'Something unknown happened.'));
        wp_die();

    }

    echo json_encode(array('error' => 'Something unknown happened.'));
    wp_die();

}



function update_cloudflare_domain_record( $blog_id, $domain ) {
    if( Multisite::is_live($blog_id) ) {
        $request = new Cloudflare();
        $request->setEmail( CLOUDFLARE_EMAIL );
        $request->setAuthKey( CLOUDFLARE_AUTH_KEY );

        $domain_info = $request->get( 'zones?name=' . $domain . '&page=1&per_page=20&direction=desc&match=all' );

        if( isset($domain_info->result[0]->id) ) {

            $records = $request->get('zones/' . $domain_info->result[0]->id . '/dns_records');

            update_blog_option( $blog_id, 'cloudflare_zone_id', $domain_info->result[0]->id );

            foreach($records->result as $record) {
                if($record->type === 'CNAME') {
                    if($record->name == $domain) {
                        $data = array(
                            'type'    => $record->type,
                            'name'    => $record->name,
                            'content' => WPENGINE_CNAME,
                            'proxied' => true
                        );
                        $updated = $request->put('zones/' . $domain_info->result[0]->id . '/dns_records/' . $record->id, $data);
                        //write_log($updated);
                        $request->delete('/zones/' . $domain_info->result[0]->id . '/purge_cache');
                    }
                }
            }
        }
    }
}



add_action("admin_footer", "delete_old_migration_imports_and_exports", 10);
function delete_old_migration_imports_and_exports() {

    if(file_exists(WP_CONTENT_DIR   . '/uploads/migration/import')) {
        $fileSystemIterator = new FilesystemIterator(WP_CONTENT_DIR   . '/uploads/migration/import');
        foreach ($fileSystemIterator as $file) {
            if ((strtotime('now') - $file->getMTime()) > 259200) {
                unlink($file->getPathname());
            }
        }
    }

    if(file_exists(WP_CONTENT_DIR   . '/uploads/migration/export')) {
        $fileSystemIterator = new FilesystemIterator(WP_CONTENT_DIR   . '/uploads/migration/export');
        foreach ($fileSystemIterator as $file) {
            if ((strtotime('now') - $file->getMTime()) > 259200) {
                unlink($file->getPathname());
            }           
        }
    }

}
















// set up an endpoint to dump site's tables on an sql file
add_action( 'rest_api_init', function () {
  register_rest_route( 'api/v1', '/migration/cloudflare', array(
    'methods' => 'POST',
    'callback' => 'rest_migration_update_cloudflare_cname',
    'show_in_index' => false,
    'middleware' => ['network', 'salesforce'],
    'permission_callback' => 'rest_api_middleware',
    ) );
  } );

function rest_migration_update_cloudflare_cname( WP_REST_Request $request ){

    $response = [
        'success' => true,
        'message' => '',
        'result' => [],
    ];

    $blog_id = !empty($request['blog_id']) ? (integer) $request['blog_id'] : '';
    $domain = !empty($request['domain']) ? $request['domain'] : '';

    if(empty($blog_id)) {
        $response['success'] = false;
        $response['message'] = 'Blog ID is missing.';
        return new WP_REST_Response( $response, 400 );
    }

    if(empty($domain)) {
        $response['success'] = false;
        $response['message'] = 'Domain is missing.';
        return new WP_REST_Response( $response, 400 );
    }

    if(!Multisite::is_live(BLOG_ID)) {
        $response['success'] = true;
        $response['message'] = 'Test site, no need for this step.';
        return new WP_REST_Response( $response, 200 );
    }

    update_cloudflare_domain_record( $blog_id, $domain);

    $response['message'] = 'Attempted to update Cloudflare.';

    return new WP_REST_Response( $result, 200 );

}





























// function export_database( $blog_id, $file_name ) {

//     global $wpdb;
//     $mysqli = new mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME ); 
//     $mysqli->select_db(DB_NAME); 
//     $mysqli->query("SET NAMES 'utf8'");
//     $target_tables = [];

//     $prefix = $wpdb->base_prefix;
//     $prefix_escaped = str_replace('_','\_',$prefix);

//     $queryTables    = $mysqli->query("SHOW TABLES LIKE '" . $prefix_escaped . $blog_id . "\_%'");     
//     while($row = $queryTables->fetch_row()) 
//     { 
//         $target_tables[] = $row[0]; 
//     }   
  
//     foreach($target_tables as $table)
//     {
//         $result         = $mysqli->query('SELECT * FROM '.$table);  
//         $fields_amount  = $result->field_count;  
//         $rows_num       = $mysqli->affected_rows;   
//         $res            = $mysqli->query('SHOW CREATE TABLE '.$table); 
//         $TableMLine     = $res->fetch_row();
//         $content        = (!isset($content) ?  "SET SQL_MODE='ALLOW_INVALID_DATES';\n" : $content) . "DROP TABLE IF EXISTS " . $table.";\n\n".$TableMLine[1].";\n\n";

//         for ($i = 0, $st_counter = 0; $i < $fields_amount;   $i++, $st_counter=0) 
//         {
//             while($row = $result->fetch_row())  
//             { //when started (and every after 100 command cycle):
//                 if ($st_counter%100 == 0 || $st_counter == 0 )  
//                 {
//                         $content .= "\nINSERT INTO ".$table." VALUES";
//                 }
//                 $content .= "\n(";
//                 for($j=0; $j<$fields_amount; $j++)  
//                 { 
//                     //$row[$j] = str_replace("\n","\\n", addslashes($row[$j]) ); 
//                     if (isset($row[$j]))
//                     {
//                         $content .= '"'.$mysqli->real_escape_string($row[$j]).'"' ; 
//                     }
//                     else 
//                     {   
//                         $content .= '""';
//                     }
//                     if ($j<($fields_amount-1))
//                     {
//                             $content.= ',';
//                     }
//                 }
//                 $content .=")";
//                 //every after 100 command cycle [or at last line] ....p.s. but should be inserted 1 cycle eariler
//                 if ( (($st_counter+1)%100==0 && $st_counter!=0) || $st_counter+1==$rows_num) 
//                 {   
//                     $content .= ";\n";
//                 } 
//                 else 
//                 {
//                     $content .= ",";
//                 } 
//                 $st_counter=$st_counter+1;
//             }
//         } 
//         $content .="\n\n\n";
//     }
//     $content .="SET SQL_MODE='NO_ZERO_DATE';";
//     file_put_contents($file_name, $content); 
    
// }



//add_action("admin_head", "test_db_file_import"); //For debugging purposes
function test_db_file_import() {
    $domain = 'aardvark.com';
    $request = new Cloudflare();
	$request->setEmail( CLOUDFLARE_EMAIL );
	$request->setAuthKey( CLOUDFLARE_AUTH_KEY );

	$domain_info = $request->get( 'zones?name=' . $domain . '&page=1&per_page=20&direction=desc&match=all' );

    if( isset($domain_info->result[0]->id) ) {

        $records = $request->get('zones/' . $domain_info->result[0]->id . '/dns_records');

        update_blog_option( $blog_id, 'cloudflare_zone_id', $domain_info->result[0]->id );

        foreach($records->result as $record) {
            if($record->type === 'CNAME') {
                if($record->name == $domain) {
                    $data = array(
                        'type'    => $record->type,
                        'name'    => $record->name,
                        'content' => WPENGINE_CNAME,
                        'proxied' => true
                    );
                    $updated = $request->put('zones/' . $domain_info->result[0]->id . '/dns_records/' . $record->id, $data);
                    //write_log($updated);
                    $request->delete('/zones/' . $domain_info->result[0]->id . '/purge_cache');
                }
            }
        }
    }
}

