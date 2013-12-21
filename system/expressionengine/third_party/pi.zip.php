<?php

/**
 *  MX Mobile Detect Class for ExpressionEngine2
 *
 * @package  ExpressionEngine
 * @subpackage Plugins
 * @category Plugins
 * @author    Max Lazar <max@eec.ms>
 * @purpose MX Zip add you capability to add files/folders into into zip archive direct from ExpressionEngine
 * @Commercial - please see LICENSE file included with this distribution
 */

require_once PATH_THIRD . 'zip/config.php';


$plugin_info = array(
	'pi_name'   => MX_ZIP_NAME,
	'pi_version'  => MX_ZIP_VER,
	'pi_author'   => MX_ZIP_AUTHOR,
	'pi_author_url'  => MX_ZIP_DOCS,
	'pi_description' => MX_ZIP_DESC,
	'pi_usage'   => zip::usage()
);


class Zip {

	var $return_data="";
	var $remove_path = "";
	var $add_path = "";
	var $large_files = "";
	var $archive_folder = "";
	var $archive_name = "";
	var $archive_fname = "";
	var $comment = null;
	var $no_compression = false;
	var $remove_all_path = false;
	var $cache_path = false;
	var $speed = 500;

	function Zip() {

		$this->EE =& get_instance();

		$LD = '\{';
		$RD = '\}';
		$SLASH = '\/';
		$tagdata = $this->EE->TMPL->tagdata;
		$variable = "zip:files";
		$file_status = true;

		$this->cache_path = ( !$this->cache_path ) ? APPPATH.'cache/' . MX_ZIP_KEY : false;


		if ( preg_match( "/".LD.$variable.".*?".RD."(.*?)".LD.'\/'.$variable.RD."/s", $tagdata, $file_list ) ) {

			$max_size = ( !$this->EE->TMPL->fetch_param( 'max_size' ) ) ?  ( 50*1024*1024 ) : ( $this->EE->TMPL->fetch_param( 'max_size' ) *1024*1024 );
			$method  = $this->EE->TMPL->fetch_param( 'method' , 'php' ) ;
			$direct_output = ( $this->EE->TMPL->fetch_param( 'direct_output' ) == 'yes' ) ? 'yes' :'no';
			$overwrite = ( $this->EE->TMPL->fetch_param( 'overwrite', false ) ) ? $this->EE->TMPL->fetch_param( 'overwrite' )  : 'no' ;

			$this->archive_name = $this->archive_fname = $this->EE->TMPL->fetch_param( 'filename', mktime().'.zip' );
			$this->archive_folder = $this->EE->TMPL->fetch_param( 'folder', '' );
			$this->large_files  = $this->EE->TMPL->fetch_param( 'large_files', 'yes' );
			$this->remove_path = $this->EE->TMPL->fetch_param( 'remove_path', NULL );
			$this->add_path = $this->EE->TMPL->fetch_param( 'add_path', NULL );
			$this->comment = $this->EE->TMPL->fetch_param( 'comment', '' );
			$this->no_compression = $this->EE->TMPL->fetch_param( 'no_compression', false );
			$this->remove_all_path = $this->EE->TMPL->fetch_param( 'remove_all_path', false );
			$this->speed = $this->EE->TMPL->fetch_param( 'speed', $this->speed );

			// $overwrite  yes / no / keep_both
			$pack_size = 0;

			$this->archive_name = $this->EE->functions->remove_double_slashes( $this->archive_folder .'/'.$this->archive_name );

			$filenames = explode( "]", str_replace( array( '&#47;', '[', "\n" ), array( '/', '', '' ), $file_list[1] ) );

			$file_status = ( file_exists( $this->archive_name ) ) ? true : false;


			// $file_status = ( file_exists( $this->archive_name ) ) ? false : true;
			// $direct_output  == 'no' &&
			// cleanup array

			foreach ( $filenames as $key => $value ) {
				$filenames[$key] = trim( $value );
			}


			if ( $overwrite == 'yes' && $file_status ) {
				unlink( $this->archive_name );
				$file_status = false;
			};

			if ( $overwrite == 'keep_both' && $file_status ) {
				
				$file_info = pathinfo($this->archive_name);
				$file_name = basename($this->archive_name, '.' . $file_info['extension']);

				for ( $i=0; $i < 99999 ; $i++ ) {
					$this->archive_fname = $file_name.$i.'.zip';
					$this->archive_name = $this->EE->functions->remove_double_slashes( $this->archive_folder .'/'.$this->archive_fname );

					$file_status = ( file_exists( $this->archive_name ) ) ? true : false;
					if ( !$file_status ) break;
				}
			};

			if ( !$file_status ) {
				$this->_backup_pkzip( $filenames );
			};

			if ( $direct_output == 'yes' ) {
				$this->_download();
				unlink( $this->archive_name );
			}
			else {
				return $this->return_data = $this->archive_name;
			}

		}
	}

	function _download() {
		$speed = round( $this->speed * 1024 );

		$type = 'application/zip';

		// Fix IE bug [0]
		$header_file = ( strstr( $_SERVER['HTTP_USER_AGENT'], 'MSIE' ) ) ? preg_replace( '/\./', '%2e', $this->archive_fname, substr_count( $this->archive_fname, '.' ) - 1 ) : $this->archive_fname;

		// Made headers
		header( "Pragma: public" );
		header( "Expires: 0" );
		header( "Cache-Control: must-revalidate, post-check=0, pre-check=0" );
		header( "Cache-Control: public", false );
		header( "Content-Description: File Transfer" );
		header( "Content-Type: " . $type );
		header( "Accept-Ranges: bytes" );
		header( "Content-Disposition: attachment; filename=\"" . $this->archive_fname . "\";" );
		header( "Content-Transfer-Encoding: binary" );
		header( "Content-Length: " . filesize( $this->archive_name ) );

		if ( $stream = fopen( $this->archive_name, 'rb' ) ) {
			while ( !feof( $stream ) && connection_status() == 0 ) {
				print( fread( $stream, $speed ) );
				flush();
				sleep( 1 );
			}

			fclose( $stream );
		};

	}


	function _backup_pkzip( $files_to_add ) {

		if ( !defined( 'PCLZIP_TEMPORARY_DIR' ) ) {
			define( 'PCLZIP_TEMPORARY_DIR', $this->cache_path );
			if ( ! is_dir( $this->cache_path ) ) {
				mkdir( $this->cache_path . "", 0777, TRUE );
			}
			if ( ! is_really_writable( $this->cache_path ) ) {

			}
		}

		$archive = new PclZip( $this->archive_name );

		if ( count( $files_to_add ) != 0 ) {

			$v_list = $archive->create( $files_to_add
				, PCLZIP_OPT_REMOVE_PATH, $this->remove_path
				, PCLZIP_OPT_ADD_PATH, $this->add_path
				, ( ( $this->no_compression ) ? PCLZIP_OPT_NO_COMPRESSION :  PCLZIP_OPT_TEMP_FILE_ON )
				, ( ( $this->remove_all_path ) ? PCLZIP_OPT_REMOVE_ALL_PATH : PCLZIP_OPT_TEMP_FILE_ON )
				, PCLZIP_OPT_COMMENT, $this->comment
				, PCLZIP_OPT_TEMP_FILE_ON
			);

			if ( $v_list == 0 )  die( "Error : " . $archive->errorInfo( true ) );

		}

		$return = true;

	}

	/*
	function _backup_system( $path, $file_list, $filename ) {

		if ( $file_list ) {
			foreach ( $file_list as $val ) {
				$archive_list = $archive_list . ' ' . $val;
			}
			$return = true;
		}

		if ( $dir_list ) {
			foreach ( $dir_list as $val ) {
				$archive_list = $archive_list . ' ' . $val;
			}
			$return = true;
		}


		if ( $return ) {
			$bk_filename = $filename . '.tgz';

			$command = "tar -cpzf $path$filename $archive_list $exclude";

			// $out = shell_exec($command);

			if ( file_exists( $path . $filename ) ) {
				$return = true;
			} else {
				$this->errors['message_failure'][] = $this->EE->lang->line( 'system_method_error' );
			}
		}
	}

	 */

	// ----------------------------------------
	//  Plugin Usage
	// ----------------------------------------

	// This function describes how the plugin is used.
	//  Make sure and use output buffering

	function usage() {
		ob_start();
?>


User Guide - http://www.eec.ms/add-ons/mx-zip


<?php
		$buffer = ob_get_contents();

		ob_end_clean();

		return $buffer;
	}
	/* END */

}

require_once PATH_THIRD . 'zip/pclzip.lib.php';
