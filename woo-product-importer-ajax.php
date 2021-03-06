<?php
    $post_data = array(
        'uploaded_file_path' => $_POST['uploaded_file_path'],
        'header_row' => $_POST['header_row'],
        'limit' => $_POST['limit'],
        'offset' => $_POST['offset'],
        'import_row' => maybe_unserialize(stripslashes($_POST['import_row'])),
        'map_to' => maybe_unserialize(stripslashes($_POST['map_to'])),
        'custom_field_name' => maybe_unserialize(stripslashes($_POST['custom_field_name'])),
        'custom_field_visible' => maybe_unserialize(stripslashes($_POST['custom_field_visible'])),
        'product_image_set_featured' => maybe_unserialize(stripslashes($_POST['product_image_set_featured']))
    );
    
    //var_dump($post_data);
    //var_dump($_POST['custom_field_name']);
    
    if(isset($post_data['uploaded_file_path'])) {
        
        $error_messages = array();
        
        //now that we have the file, grab contents
        $temp_file_path = $post_data['uploaded_file_path'];
        $handle = fopen( $temp_file_path, 'r' );
        $import_data = array();
        
        if ( $handle !== FALSE ) {
            while ( ( $line = fgetcsv($handle) ) !== FALSE ) {
                $import_data[] = $line;
            }
            fclose( $handle );
        } else {
            $error_messages[] = 'Could not open CSV file.';
        }
        
        if(sizeof($import_data) == 0) {
            $error_messages[] = 'No data found in CSV file.';
        }
        
        //discard header row from data set, if we have one
        if(intval($post_data['header_row']) == 1) array_shift($import_data);
        
        //total size of data to import (not just what we're doing on this pass)
        $row_count = sizeof($import_data);
        
        //slice down our data based on limit and offset params
        $limit = intval($post_data['limit']);
        $offset = intval($post_data['offset']);
        if($limit > 0 || $offset > 0) {
            $import_data = array_slice($import_data, $offset , ($limit > 0 ? $limit : null), true);
        }
        
        //a few stats about the current operation to send back to the browser.
        $rows_remaining = ($row_count - ($offset + $limit)) > 0 ? ($row_count - ($offset + $limit)) : 0;
        $insert_count = ($row_count - $rows_remaining);
        $insert_percent = number_format(($insert_count / $row_count) * 100, 1);
        
        //array that will be sent back to the browser with info about what we inserted.
        $inserted_rows = array();
        
        //this is where the fun begins
        foreach($import_data as $row_id => $row) {
            
            //don't import if the checkbox wasn't checked
            //only applies when row_count is less than 100
            if($row_count < 100 && intval($post_data['import_row'][$row_id]) != 1) continue;
            
            //unset new_post_id
            $new_post_id = null;
            
            //set some initial post values
            $new_post = array();
            $new_post['post_type'] = 'product';
            $new_post['post_status'] = 'publish';
            $new_post['post_title'] = '';
            $new_post['post_content'] = '';
            
            //set some initial post_meta values
            $new_post_meta = array();
            $new_post_meta['_visibility'] = 'visible';
            $new_post_meta['_featured'] = 'no';
            $new_post_meta['_weight'] = 0;
            $new_post_meta['_length'] = 0;
            $new_post_meta['_width'] = 0;
            $new_post_meta['_height'] = 0;
            $new_post_meta['_sku'] = '';
            $new_post_meta['_stock'] = '';
            $new_post_meta['_stock_status'] = 'instock';
            $new_post_meta['_sale_price'] = '';
            $new_post_meta['_sale_price_dates_from'] = '';
            $new_post_meta['_sale_price_dates_to'] = '';
            $new_post_meta['_tax_status'] = 'taxable';
            $new_post_meta['_tax_class'] = '';
            $new_post_meta['_purchase_note'] = '';
            $new_post_meta['_downloadable'] = 'no';
            $new_post_meta['_virtual'] = 'no';
            $new_post_meta['_backorders'] = 'no';
            $new_post_meta['_manage_stock'] = 'no';
            
            //stores tax and term ids so we can associate our product with terms and taxonomies
            //this is a multidimensional array
            //format is: array( 'tax_name' => array(1, 3, 4), 'another_tax_name' => array(5, 9, 23) )
            $new_post_terms = array();
            
            //a list of woocommerce "custom fields" to be added to product.
            $new_post_custom_fields = array();
            $new_post_custom_field_count = 0;
            
            //a list of image URLs to be downloaded.
            $new_post_images = array();
            
            //keep track of any errors or messages generated during post insert or image downloads.
            $new_post_errors = array();
            $new_post_messages = array();
            
            //track whether or not the post was actually inserted.
            $new_post_insert_success = false;
            
            foreach($row as $key => $col) {
                $map_to = $post_data['map_to'][$key];
                
                //skip if the column is blank.
                //useful when two CSV cols are mapped to the same product field.
                //you would do this to merge two columns in your CSV into one product field.
                if(strlen($col) == 0) {
                    continue;
                }
                
                //validate col value if necessary
                switch($map_to) {
                    case '_downloadable':
                    case '_virtual':
                    case '_manage_stock':
                    case '_featured':
                        if(!in_array($col, array('yes', 'no'))) continue;
                        break;
                    
                    case '_visibility':
                        if(!in_array($col, array('visible', 'catalog', 'search', 'hidden'))) continue;
                        break;
                    
                    case '_stock_status':
                        if(!in_array($col, array('instock', 'outofstock'))) continue;
                        break;
                    
                    case '_backorders':
                        if(!in_array($col, array('yes', 'no', 'notify'))) continue;
                        break;
                    
                    case '_tax_status':
                        if(!in_array($col, array('taxable', 'shipping', 'none'))) continue;
                        break;
                    
                    case '_product_type':
                        if(!in_array($col, array('simple', 'variable', 'grouped', 'external'))) continue;
                        break;
                }
                
                //prepare the col value for insertion into the database
                switch($map_to) {
                    case 'post_title':
                    case 'post_content':
                    case 'post_excerpt':
                        $new_post[$map_to] = $col;
                        break;
                    
                    case '_weight':
                    case '_length':
                    case '_width':
                    case '_height':
                    case '_regular_price':
                    case '_sale_price':
                    case '_price':
                        //remove any non-numeric chars except for '.'
                        $new_post_meta[$map_to] = preg_replace("/[^0-9.]/", "", $col);
                        break;
                    
                    case '_tax_status':
                    case '_tax_class':
                    case '_visibility':
                    case '_featured':
                    case '_sku':
                    case '_downloadable':
                    case '_virtual':
                    case '_stock':
                    case '_stock_status':
                    case '_backorders':
                    case '_manage_stock':
                    case '_product_type':
                    case '_product_url':
                        $new_post_meta[$map_to] = $col;
                        break;
                    
                    case 'product_cat_by_name':
                    case 'product_tag_by_name':
                        $tax = str_replace('_by_name', '', $map_to);
                        $term_names = explode('|', $col);
                        foreach($term_names as $term_name) {
                            $term = get_term_by('name', $term_name, $tax, 'ARRAY_A');
                            
                            //if term does not exist, try to insert it.
                            if($term === false) {
                                $term = wp_insert_term($term_name, $tax);
                            }
                            
                            //if we got a term, save the id so we can associate
                            if(is_array($term)) {
                                $new_post_terms[$tax][] = intval($term['term_id']);
                            }
                        }
                        break;
                    
                    case 'product_cat_by_id':
                    case 'product_tag_by_id':
                        $tax = str_replace('_by_id', '', $map_to);
                        $term_ids = explode('|', $col);
                        foreach($term_ids as $term_id) {
                            $term = get_term_by('id', $term_id, $tax, 'ARRAY_A');
                            
                            //if we got a term, save the id so we can associate
                            if($term !== false && is_array($term)) {
                                $new_post_terms[$tax][] = intval($term['term_id']);
                            } else {
                                $new_post_errors[] = "Couldn't find {$tax} with ID {$term_id}.";
                            }
                            
                        }
                        break;
                    
                    case 'custom_field':
                        $field_name = $post_data['custom_field_name'][$key];
                        $field_slug = sanitize_title($field_name);
                        $visible = intval($post_data['custom_field_visible'][$key]);
                        
                        $new_post_custom_fields[$field_slug] = array (
                            "name" => $field_name,
                            "value" => $col,
                            "position" => $new_post_custom_field_count++,
                            "is_visible" => $visible,
                            "is_variation" => 0,
                            "is_taxonomy" => 0
                        );
                        break;
                    
                    case 'product_image':
                        $image_urls = explode('|', strtolower($col));
                        if(is_array($image_urls)) {
                            $new_post_images = array_merge($new_post_images, $image_urls);
                        }
                        
                        break;
                }
            }
            
            //set some more post_meta and parse things as appropriate
            $new_post_meta['_regular_price'] = $new_post_meta['_price'];
            $new_post_meta['_product_attributes'] = serialize($new_post_custom_fields);
            
            //try to find a product with a matching SKU
            $existing_product = null;
            if(strlen($new_post_meta['_sku']) > 0) {
                $existing_post_query = array(
                    'numberposts' => 1,
                    'meta_key' => '_sku',
                    'meta_query' => array(
                        array(
                            'key'=>'_sku',
                            'value'=> $new_post_meta['_sku'],
                            'compare' => '='
                        )
                    ),
                    'post_type' => 'product');
                $existing_posts = get_posts($existing_post_query);
                if(is_array($existing_posts) && sizeof($existing_posts) > 0) {
                    $existing_product = array_shift($existing_posts);
                }
            }
            
            if(strlen($new_post['post_title']) > 0 || $existing_product !== null) {
                
                //insert/update product
                if($existing_product !== null) {
                    $new_post_messages[] = 'Updating product with ID '.$existing_product->ID.'.';
                    
                    $new_post['ID'] = $existing_product->ID;
                    $new_post_id = wp_update_post($new_post);
                } else {
                    $new_post_id = wp_insert_post($new_post, true);
                }
                
                if(is_wp_error($new_post_id)) {
                    $new_post_errors[] = 'Couldn\'t insert product with name "'.$new_post['post_title'].'".';
                } elseif($new_post_id == 0) {
                    $new_post_errors[] = 'Couldn\'t update product with ID "'.$new_post['ID'].'".';
                } else {
                    //insert successful!
                    $new_post_insert_success = true;
                    
                    //set post_meta on inserted post
                    foreach($new_post_meta as $meta_key => $meta_value) {
                        add_post_meta($new_post_id, $meta_key, $meta_value, true) or
                            update_post_meta($new_post_id, $meta_key, $meta_value);
                    }
                    
                    //set post terms on inserted post
                    foreach($new_post_terms as $tax => $term_ids) {
                        wp_set_object_terms($new_post_id, $term_ids, $tax);
                    }
                    
                    //figure out where the uploads folder lives
                    $wp_upload_dir = wp_upload_dir();
                    
                    //grab product images
                    foreach($new_post_images as $image_index => $image_url) {
                        
                        //convert space chars into their hex equivalent.
                        //thanks to github user 'becasual' for submitting this change
                        $image_url = str_replace(' ', '%20', trim($image_url));
                        
                        //do some parsing on the image url so we can take a look at
                        //its file extension and file name
                        $parsed_url = parse_url($image_url);
                        $pathinfo = pathinfo($parsed_url['path']);
                        
                        //If our 'image' file doesn't have an image file extension, skip it.
                        $allowed_extensions = array('jpg', 'jpeg', 'gif', 'png');
                        $image_ext = strtolower($pathinfo['extension']);
                        if(!in_array($image_ext, $allowed_extensions)) {
                            $new_post_errors[] = "A valid file extension wasn't found in '$image_url'. Extension found was '$image_ext'. Allowed extensions are: ".implode(',', $allowed_extensions);
                            continue;
                        }
                        
                        //figure out where we're putting this thing.
                        $dest_filename = wp_unique_filename( $wp_upload_dir['path'], $pathinfo['basename'] );
                        $dest_path = $wp_upload_dir['path'] . '/' . $dest_filename;
                        $dest_url = $wp_upload_dir['url'] . '/' . $dest_filename;
                        
                        //download the image to our local server.
                        // if allow_url_fopen is enabled, we'll use that. Otherwise, we'll try cURL
                        if(ini_get('allow_url_fopen')) {
                            //attempt to copy() file show error on failure.
                            if( ! @copy($image_url, $dest_path)) {
                                $http_status = $http_response_header[0];
                                $new_post_errors[] = "'{$http_status}' encountered while attempting to download '$image_url'.";
                            }
                            
                        } elseif(function_exists('curl_init')) {
                            $ch = curl_init($image_url);
                            $fp = fopen($dest_path, "wb");
                            
                            $options = array(
                                CURLOPT_FILE => $fp,
                                CURLOPT_HEADER => 0,
                                CURLOPT_FOLLOWLOCATION => 1,
                                CURLOPT_TIMEOUT => 60); // in seconds
                            
                            curl_setopt_array($ch, $options);
                            curl_exec($ch);
                            $http_status = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
                            curl_close($ch);
                            fclose($fp);
                            
                            //delete the file if the download was unsuccessful
                            if($http_status != 200) {
                                unlink($dest_path);
                                $new_post_errors[] = "HTTP status '{$http_status}' encountered while attempting to download '$image_url'.";
                            }
                        } else {
                            //well, damn. no joy, as they say.
                            $error_messages[] = "Looks like allow_url_fopen is off and cURL is not enabled. No images were imported.";
                            break;
                        }
                        
                        //make sure we actually got the file.
                        if(!file_exists($dest_path)) {
                            $new_post_errors[] = "Couldn't download file '$image_url'.";
                            continue;
                        }
                        
                        //whew. are we there yet?
                        
                        //add a post of type 'attachment' so this item shows up in the WP Media Library.
                        //our imported product will be the post's parent.
                        $wp_filetype = wp_check_filetype($dest_path);
                        $attachment = array(
                            'guid' => $dest_url, 
                            'post_mime_type' => $wp_filetype['type'],
                            'post_title' => preg_replace('/\.[^.]+$/', '', $dest_filename),
                            'post_content' => '',
                            'post_status' => 'inherit'
                        );
                        $attachment_id = wp_insert_attachment( $attachment, $dest_path, $new_post_id );
                        // you must first include the image.php file
                        // for the function wp_generate_attachment_metadata() to work
                        require_once(ABSPATH . 'wp-admin/includes/image.php');
                        $attach_data = wp_generate_attachment_metadata( $attachment_id, $dest_path );
                        wp_update_attachment_metadata( $attachment_id, $attach_data );
                        
                        //set the image as featured if it is the first image in the set AND
                        //the user checked the box on the preview page.
                        if($image_index == 0 && intval($post_data['product_image_set_featured'][$key]) == 1) {
                            update_post_meta($new_post_id, '_thumbnail_id', $attachment_id);
                        }
                    }
                }
                
            } else {
                $new_post_errors[] = 'Skipped import of product without a name';
            }
            
            //this is returned back to the results page.
            //any fields that should show up in results should be added to this array.
            $inserted_rows[] = array(
                'row_id' => $row_id,
                'post_id' => $new_post_id ? $new_post_id : '',
                'name' => $new_post['post_title'] ? $new_post['post_title'] : '',
                'sku' => $new_post_meta['_sku'] ? $new_post_meta['_sku'] : '',
                'price' => $new_post_meta['_price'] ? $new_post_meta['_price'] : '',
                'has_errors' => (sizeof($new_post_errors) > 0),
                'errors' => $new_post_errors,
                'has_messages' => (sizeof($new_post_messages) > 0),
                'messages' => $new_post_messages,
                'success' => $new_post_insert_success
            );
        }
    }
    
    echo json_encode(array(
        'remaining_count' => $rows_remaining,
        'row_count' => $row_count,
        'insert_count' => $insert_count,
        'insert_percent' => $insert_percent,
        'inserted_rows' => $inserted_rows,
        'error_messages' => $error_messages,
        'limit' => $limit,
        'new_offset' => ($limit + $offset)
    ));