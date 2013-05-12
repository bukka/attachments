<?php

// Exit if accessed directly
if( !defined( 'ABSPATH' ) ) exit;

/**
* Migration class for legacy Attachments data
*
* @since 3.1.3
*/
class AttachmentsMigrate extends Attachments
{

    function __construct()
    {
        parent::__construct();
    }

    /**
     * Migrate Attachments 1.x records to 3.0's format
     *
     * @since 3.0
     */
    function migrate( $instance = null, $title = null, $caption = null )
    {
        // sanitize
        if( is_null( $instance ) || empty( $instance ) || is_null( $title ) || is_null( $caption ) )
            return false;

        $instance   = str_replace( '-', '_', sanitize_title( $instance ) );
        $title      = empty( $title ) ? false : str_replace( '-', '_', sanitize_title( $title ) );
        $caption    = empty( $caption ) ? false : str_replace( '-', '_', sanitize_title( $caption ) );

        // we need our deprecated functions
        include_once( ATTACHMENTS_DIR . '/deprecated/get-attachments.php' );

        $legacy_attachments_settings = get_option( 'attachments_settings' );

        $query = false;

        if( $legacy_attachments_settings && is_array( $legacy_attachments_settings['post_types'] ) && count( $legacy_attachments_settings['post_types'] ) )
        {
            // we have legacy settings, so we're going to use the post types
            // that Attachments is currently utilizing

            // the keys are the actual CPT names, so we need those
            foreach( $legacy_attachments_settings['post_types'] as $post_type => $value )
                if( $value )
                    $post_types[] = $post_type;

            // set up our WP_Query args to grab anything with legacy data
            $args = array(
                    'post_type'         => isset( $post_types ) ? $post_types : array(),
                    'post_status'       => 'any',
                    'posts_per_page'    => -1,
                    'meta_key'          => '_attachments',
                    'suppress_filters'  => true,
                );

            $query = new WP_Query( $args );
        }

        $count = 0;

        // loop through each post
        if( $query ) { while( $query->have_posts() )
        {
            // set up postdata
            $query->the_post();

            // let's first decode our Attachments data
            $existing_attachments = get_post_meta( $query->post->ID, '_attachments', false );

            $post_attachments = array();

            // check to make sure we've got data
            if( is_array( $existing_attachments ) && count( $existing_attachments ) > 0 )
            {
                // loop through each existing attachment
                foreach( $existing_attachments as $attachment )
                {
                    // decode and unserialize the data
                    $data = unserialize( base64_decode( $attachment ) );

                    array_push( $post_attachments, array(
                        'id'        => stripslashes( $data['id'] ),
                        'title'     => stripslashes( $data['title'] ),
                        'caption'   => stripslashes( $data['caption'] ),
                        'order'     => stripslashes( $data['order'] )
                        ));
                }

                // sort attachments
                if( count( $post_attachments ) > 1 )
                {
                    usort( $post_attachments, 'attachments_cmp' );
                }
            }

            // we have our Attachments entries

            // let's check to see if we're migrating after population has taken place
            $existing_attachments = get_post_meta( $query->post->ID, $this->get_meta_key(), false );

            if( !isset( $existing_attachments[0] ) )
                $existing_attachments[0] = '';

            $existing_attachments = json_decode( $existing_attachments[0] );

            if( !is_object( $existing_attachments ) )
                $existing_attachments = new stdClass();

            // we'll loop through the legacy Attachments and save them in the new format
            foreach( $post_attachments as $legacy_attachment )
            {
                // convert to the new format
                $converted_attachment = array( 'id' => $legacy_attachment['id'] );

                // fields are technically optional so we'll add those separately
                // we're also going to encode them in the same way the main class does
                if( $title )
                    $converted_attachment['fields'][$title] = htmlentities( stripslashes( $legacy_attachment['title'] ), ENT_QUOTES, 'UTF-8' );

                if( $caption )
                    $converted_attachment['fields'][$caption] = htmlentities( stripslashes( $legacy_attachment['caption'] ), ENT_QUOTES, 'UTF-8' );

                // check to see if the existing Attachments have our target instance
                if( !isset( $existing_attachments->$instance ) )
                {
                    // the instance doesn't exist so we need to create it
                    $existing_attachments->$instance = array();
                }

                // we need to convert our array to an object
                $converted_attachment['fields'] = (object) $converted_attachment['fields'];
                $converted_attachment = (object) $converted_attachment;

                // append this legacy attachment to the existing instance
                array_push( $existing_attachments->$instance, $converted_attachment );
            }

            // we're done! let's save everything in our new format
            $existing_attachments = version_compare( PHP_VERSION, '5.4.0', '>=' ) ? json_encode( $existing_attachments, JSON_UNESCAPED_UNICODE ) : json_encode( $existing_attachments );

            // save it to the database
            update_post_meta( $query->post->ID, 'attachments', $existing_attachments );

            // increment our counter
            $count++;
        } }

        return $count;
    }



    /**
     * Step 1 of the migration process. Allows the user to define the target instance and field names.
     *
     * @since 3.2
     */
    function prepare_migration()
    {
        if( !wp_verify_nonce( $_GET['nonce'], 'attachments-migrate-1') ) wp_die( __( 'Invalid request', 'attachments' ) );
        ?>
            <h3><?php _e( 'Migration Step 1', 'attachments' ); ?></h3>
            <p><?php _e( "In order to migrate Attachments 1.x data, you need to set which instance and fields in version 3.0+ you'd like to use:", 'attachments' ); ?></p>
            <form action="options-general.php" method="get">
                <input type="hidden" name="page" value="attachments" />
                <input type="hidden" name="migrate" value="2" />
                <input type="hidden" name="nonce" value="<?php echo wp_create_nonce( 'attachments-migrate-2' ); ?>" />
                <table class="form-table">
                    <tbody>
                        <tr valign="top">
                            <th scope="row">
                                <label for="attachments-instance"><?php _e( 'Attachments 3.x Instance', 'attachments' ); ?></label>
                            </th>
                            <td>
                                <input name="attachments-instance" id="attachments-instance" value="attachments" class="regular-text" />
                                <p class="description"><?php _e( 'The instance name you would like to use in the migration. Required.', 'attachments' ); ?></p>
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">
                                <label for="attachments-title"><?php _e( 'Attachments 3.x Title', 'attachments' ); ?></label>
                            </th>
                            <td>
                                <input name="attachments-title" id="attachments-title" value="title" class="regular-text" />
                                <p class="description"><?php _e( 'The <code>Title</code> field data will be migrated to this field name in Attachments 3.x. Leave empty to disregard.', 'attachments' ); ?></p>
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">
                                <label for="attachments-caption"><?php _e( 'Attachments 3.x Caption', 'attachments' ); ?></label>
                            </th>
                            <td>
                                <input name="attachments-caption" id="attachments-caption" value="caption" class="regular-text" />
                                <p class="description"><?php _e( 'The <code>Caption</code> field data will be migrated to this field name in Attachments 3.x. Leave empty to disregard.', 'attachments' ); ?></p>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p class="submit">
                    <input type="submit" name="submit" id="submit" class="button button-primary" value="<?php esc_attr_e( 'Start Migration', 'attachments' ); ?>" />
                </p>
            </form>
        <?php
    }



    /**
     * Step 2 of the migration process. Validates migration requests and fires the migration method.
     *
     * @since 3.2
     */
    function init_migration()
    {
        if( !wp_verify_nonce( $_GET['nonce'], 'attachments-migrate-2') )
            wp_die( __( 'Invalid request', 'attachments' ) );

        $total = $this->migrate( $_GET['attachments-instance'], $_GET['attachments-title'], $_GET['attachments-caption'] );

        if( false == get_option( 'attachments_migrated' ) ) :
        ?>
            <h3><?php _e( 'Migration Complete!', 'attachments' ); ?></h3>
            <p><?php _e( 'The migration has completed.', 'attachments' ); ?> <strong><?php _e( 'Migrated', 'attachments'); ?>: <?php echo $total; ?></strong>.</p>
        <?php else : ?>
            <h3><?php _e( 'Migration has already Run!', 'attachments' ); ?></h3>
            <p><?php _e( 'The migration has already been run. The migration process has not been repeated.', 'attachments' ); ?></p>
        <?php endif;

        // make sure the database knows the migration has run
        add_option( 'attachments_migrated', true, '', 'no' );
    }



    /**
     * Step 1 of the Pro migration process. Allows the user to define the target instance and field names.
     *
     * @since 3.5
     */
    function prepare_pro_migration()
    {
        if( !wp_verify_nonce( $_GET['nonce'], 'attachments-pro-migrate-1') ) wp_die( __( 'Invalid request', 'attachments' ) );
        ?>
        <h3><?php _e( 'Migration Step 1', 'attachments' ); ?></h3>
        <form action="options-general.php" method="get">
            <input type="hidden" name="page" value="attachments" />
            <input type="hidden" name="migrate-pro" value="2" />
            <input type="hidden" name="nonce" value="<?php echo wp_create_nonce( 'attachments-pro-migrate-2' ); ?>" />

            <?php
                /**
                 * We need to use the stored Attachments Pro settings to generate a dynamic form encompassing all
                 * Pro instances, their fields, their rules, and their auto-append statuses
                 */
                $attachments_pro_settings = get_option( '_iti_apro_settings' );
            ?>

            <?php if( is_array( $attachments_pro_settings['positions'] ) ) : ?>
                <p><?php _e( 'The following Attachments Pro Instances will be migrated:', 'attachments' ); ?></p>
                <ul style="padding-left:32px;list-style:disc;">
                    <?php foreach( $attachments_pro_settings['positions'] as $attachments_pro_instance ) : ?>
                        <li><?php echo $attachments_pro_instance['label']; ?></li>
                    <?php endforeach; ?>
                </ul>
                <p><?php _e( 'Each Pro Instance will be migrated to an equivalent Attachments Instance, and you will be provided code to copy and paste into your <code>functions.php</code>', 'attachments' ); ?></p>
            <?php endif; ?>

            <p class="submit">
                <input type="submit" name="submit" id="submit" class="button button-primary" value="<?php esc_attr_e( 'Start Migration', 'attachments' ); ?>" />
            </p>
        </form>
    <?php
    }



    /**
     * Step 2 of the Pro migration process. Validates migration requests and fires the migration method.
     *
     * @since 3.5
     */
    function init_pro_migration()
    {
        if( !wp_verify_nonce( $_GET['nonce'], 'attachments-pro-migrate-2') )
            wp_die( __( 'Invalid request', 'attachments' ) );

        $attachments_pro_settings = get_option( '_iti_apro_settings' );
        if( is_array( $attachments_pro_settings['positions'] ) )
        {
            $totals = array();
            foreach( $attachments_pro_settings['positions'] as $attachments_pro_instance )
            {
                $totals[] = $this->migrate_pro( $attachments_pro_instance );
            }

            $total_attachments = 0;
            if( !empty( $totals ) )
                foreach( $totals as $instance_total )
                    $total_attachments += $instance_total['total'];

            if( false == get_option( 'attachments_pro_migrated' ) ) :
                ?>
                <h3><?php _e( 'Migration Complete!', 'attachments' ); ?></h3>
                <p><?php _e( 'The migration has completed.', 'attachments' ); ?> <strong><?php _e( 'Migrated', 'attachments'); ?>: <?php echo $total_attachments; ?></strong>.</p>
            <?php else : ?>
                <h3><?php _e( 'Migration has already Run!', 'attachments' ); ?></h3>
                <p><?php _e( 'The migration has already been run. The migration process has not been repeated.', 'attachments' ); ?></p>
            <?php endif;
        }

        // make sure the database knows the migration has run
        // add_option( 'attachments_pro_migrated', true, '', 'no' );
    }



    function migrate_pro( $instance = array() )
    {
        echo '<pre>';
        if( !is_array( $instance ) || empty( $instance ) )
            return false;

        $post_types = get_post_types();

        // set up our WP_Query args to grab anything (really anything) with legacy data
        $args = array(
            'post_type'         => !empty( $post_types ) ? $post_types : array( 'post', 'page' ),
            'post_status'       => 'any',
            'posts_per_page'    => -1,
            'meta_key'          => '_attachments_pro',
            'suppress_filters'  => true,
        );

        $query = new WP_Query( $args );

        $count = array( 'instance' => $instance['name'], 'total' => 0 );

        if( $query )
        {
            while( $query->have_posts() )
            {
                // set up postdata
                $query->the_post();

                // let's first decode our Attachments Pro data
                $existing_instances = get_post_meta( $query->post->ID, '_attachments_pro', true );
                $existing_instances = isset( $existing_instances['attachments'] ) ? $existing_instances['attachments'] : false;

                // check to make sure we've got data
                if( is_array( $existing_instances ) && count( $existing_instances ) > 0 )
                {
                    $post_attachments = array();
                    foreach( $existing_instances as $instance_name => $instance_attachments )
                    {
                        $post_attachments[$instance_name] = array();
                        $converted_attachment = array();
                        foreach( $instance_attachments as $instance_attachment )
                        {
                            $converted_attachment['id'] = $instance_attachment['id'];
                            if( is_array( $instance_attachment['fields'] ) )
                            {
                                $converted_attachment['fields'] = array();
                                foreach( $instance_attachment['fields'] as $instance_attachment_field_key => $instance_attachment_field )
                                {
                                    $destination_field_name = $instance['fields'][$instance_attachment_field_key]['label'];
                                    $destination_field_name = str_replace( '-', '_', sanitize_title( $destination_field_name ) );

                                    $converted_attachment['fields'][$destination_field_name] = $instance_attachment_field;
                                }
                            }
                            $post_attachments[$instance_name][] = $converted_attachment;
                            $count['total']++;
                        }
                    }

                    if( !empty( $post_attachments ) )
                    {
                        // before saving the converted data, we need to see if there is existing Attachments data
                        $this->apply_init_filters();
                        $existing_attachments = get_post_meta( $query->post->ID, $this->get_meta_key(), false );

                        if( !isset( $existing_attachments[0] ) )
                            $existing_attachments[0] = '';

                        $existing_attachments = json_decode( $existing_attachments[0] );

                        if( !is_object( $existing_attachments ) )
                            $existing_attachments = new stdClass();

                        foreach( $post_attachments as $instance => $attachments )
                        {
                            // check to see if the existing Attachments have our target instance
                            if( !isset( $existing_attachments->$instance ) )
                            {
                                // the instance doesn't exist so we need to create it
                                $existing_attachments->$instance = array();
                            }

                            // loop through the instance attachments and integrate new and old
                            foreach( $attachments as $attachment )
                            {
                                // we need to convert our array to an object
                                $attachment['fields'] = (object) $attachment['fields'];
                                $attachment = (object) $attachment;

                                array_push( $existing_attachments->$instance, $attachment );
                            }
                        }

                        // now we can save
                        $existing_attachments = version_compare( PHP_VERSION, '5.4.0', '>=' ) ? json_encode( $existing_attachments, JSON_UNESCAPED_UNICODE ) : json_encode( $existing_attachments );
                        update_post_meta( $query->post->ID, $this->get_meta_key(), $existing_attachments );

                    }
                }
            }
        }

        echo '</pre>';
        return $count;
    }

}
