<style type="text/css">
.status-ok { color:#008000 !important; }
.status-incomplete { color:#FF0000 !important; }
.log-file { width:100%; height:300px; }
</style>

<?php if ( 'off' == strtolower( ini_get( 'safe_mode' ) ) ) : ?>
    <div class="error"><p><?php _e( "It won't be possible to increase the PHP execution time on this server. The plugin might fail silently if you have a lot of posts. To work properly we need the <code>safe_mode</code> PHP option available.", 'sfg' ); ?></p></div>
<?php endif; ?>

<?php if ( !$this->_mkdir() ) : ?>
    <div class="error"><p class="warning"><?php printf( __( "It wasn't possible to create the sitemaps directory. Please make sure that the <code>%s</code> exists and is writable by the web server user.", 'sfg' ), $this->xmldir ); ?></p></div>
<?php endif; ?>

<div class="wrap sfg-admin">
<div class="icon32" id="icon-options-general"><br></div><h2>Sitemap Files Generator</h2>

<p><?php printf( __( 'To generate your sitemaps files visit this <a href="%s">secret link</a>. You probably want to use it for automatic generation. The following line in a crontab will create your sitemaps every 4 hours:', 'sfg'), $this->secret_link ); ?></p>

<pre>
<code>0 */4 * * * www-data /usr/bin/curl --silent <?php echo $this->secret_link; ?> > /dev/null 2>&1</code>
</pre>

<?php if ( empty( $this->sitemap_files ) ) : ?>
    <p><?php _e( "You don't have any files generated yet.", 'sfg' ); ?></p>
<?php else : ?>

    <h4><?php _e( 'Generated files', 'sfg' ); ?></h4>

    <table class="widefat">
    <thead><tr>
        <th><?php _e( 'File', 'sfg' ); ?></th>
        <th><?php _e( 'Size', 'sfg' ); ?></th>
        <th><?php _e( 'Generation date', 'sfg' ); ?></th>
        <th><?php _e( 'Status', 'sfg' ); ?></th>
    </tr></thead>
    <?php foreach ( $this->sitemap_files as $file ) : ?>
        <tr>
            <td><a href="<?php echo $file['url']; ?>"><?php echo $file['name']; ?></a></td>
            <td><?php echo $file['size']; ?></td>
            <td><?php echo $file['mod']; ?></td>
            <td class="status-<?php echo $file['status'] ? 'ok' : 'incomplete'; ?>">
                <?php echo $file['status'] ? __( 'OK', 'sfg' ) : __( 'Incomplete', 'sfg' ); ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </table>

    <h4><?php _e( 'Log file', 'sfg' ); ?></h4>

    <textarea class="log-file"><?php echo file_get_contents( $this->log_file ); ?></textarea>

<?php endif; ?>

</div>
