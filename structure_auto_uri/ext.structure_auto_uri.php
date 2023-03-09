<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

/**
* Structure Auto URI Extension class
*
* @package			Structure Auto URI
* @version			1.3.3
* @author			Simon Andersohn
* @link				
* @license			
*/

class Structure_auto_uri_ext
{

	var $name 			= 'Structure Auto URI';
	var $version 		= '1.3.3';
	var $description 	= 'Automatically updates your url_title field to your structure URI';
	var $settings_exist = 'y';
	var $docs_url 		= '';
    
	
	/**
	 * Class Constructor
	 */
	function __construct($settings = array())
	{
		// Make a local reference to the ExpressionEngine super object
		$this->EE = get_instance();

		$this->settings = $settings;
	}

	// --------------------------------------------------------------------

	/**
	 * Activate Extension
	 */
	function activate_extension()
	{
		// -------------------------------------------
		//  Add the extension hooks
		// -------------------------------------------

		$hooks = array(
			'cp_js_end'
		);

		foreach($hooks as $hook)
		{
			$this->EE->db->insert('extensions', array(
				'class'    => get_class($this),
				'method'   => $hook,
				'hook'     => $hook,
				'settings' => '',
				'priority' => 10,
				'version'  => $this->version,
				'enabled'  => 'y'
			));
		}
	}

	/**
	 * Update Extension
	 */
	function update_extension($current = '')
	{
		// Remove usage of publish_form_entry_data hook, it is unused
		if (version_compare($current, '1.3', '>='))
		{
			ee()->db->delete(
				'extensions',
				array(
					'class'	=> __CLASS__,
					'hook'	=> 'publish_form_entry_data'
				)
			);
		}

		return TRUE;
	}

	/**
	 * Disable Extension
	 */
	function disable_extension()
	{
		// -------------------------------------------
		//  Delete the extension hooks
		// -------------------------------------------

		$this->EE->db->where('class', get_class($this))
		             ->delete('exp_extensions');
	}

	function settings()
	{
		$channels = array();
		
		$this->EE->db->select('*')
				->from('structure_channels')
				->where('structure_channels.site_id', $this->EE->config->item('site_id'))
				->where("(type = 'page' OR type = 'listing')")
				->join('channels', 'channels.channel_id = structure_channels.channel_id', 'inner')
				->order_by('channel_title', 'asc');
		$query = $this->EE->db->get();
		
		foreach($query->result_array() as $row)
		{
			$channels[$row['channel_id']] = $row['channel_title'];
		}
		
		$settings = array(
			'include_channels' => array('ms', $channels, ''),
			'include_channels_templates'  => array('ms', $channels, ''),
			'exclude_uris' => array('t', array('rows' => '10'), '')
		);

		return $settings;
	}
	
		
	// --------------------------------------------------------------------
	
	/**
	 * cp_js_end ext hook
	 */
	function cp_js_end()
	{
		$data = '';
		
		if (ee()->extensions->last_call !== FALSE)
		{
			$data = ee()->extensions->last_call;
		}

		$include_channels = array();
		$include_channels_templates = array();
		$exclude_uris = array();
		
		if (isset($this->settings['exclude_uris'])) {
			$exclude_uris = preg_split('/\r\n|\r|\n|\|/', $this->settings['exclude_uris']);
		}
		if (isset($this->settings['include_channels'])) {
			$include_channels = $this->settings['include_channels'];
		}
		if (isset($this->settings['include_channels_templates'])) {
			$include_channels_templates = $this->settings['include_channels_templates'];
		}
		
		if (!empty($include_channels) || !empty($include_channels_templates))
		{
			
			// Update with selected template as set within the structure settings
			$this->EE->db->where('site_id', $this->EE->config->item('site_id'));
			$this->EE->db->where("(type = 'page' OR type = 'listing')");
			$query = $this->EE->db->get('exp_structure_channels');
		
			if ($query->num_rows() > 0)
			{
				$include_channels_js = json_encode($include_channels, JSON_NUMERIC_CHECK);
				$include_channels_templates_js = json_encode($include_channels_templates, JSON_NUMERIC_CHECK);
				$exclude_uris_js = json_encode($exclude_uris);
				

				$data .= '
					/* STRUCTURE AUTO URI */
					var channel_templates = new Array();
				';
				foreach($query->result_array() as $row)
				{
					$data .= '
						channel_templates['.$row['channel_id'].'] = [];
						channel_templates['.$row['channel_id'].']["channel_id"] = '.$row['channel_id'].';
						channel_templates['.$row['channel_id'].']["template_id"] = '.$row['template_id'].';
						channel_templates['.$row['channel_id'].']["type"] = "'.$row['type'].'";
					';
				}
				
				$data .= '
					var include_channels = '.$include_channels_js.';
					var include_channels_templates = '.$include_channels_templates_js.';
					var exclude_uris = '.$exclude_uris_js.';
					
					$("#publishForm, .box.publish, .ee-main__content .form-standard .panel-body__publish").each(function() {

						$publishForm = $(this);

						// Change selected template if channel is changed
						$("select[name=new_channel], select[name=channel_id], input[name=channel_id]", $publishForm).on("change", function() {
							if (channel_templates[$(this).val()] != "undefined" && $.inArray( $("input[name=structure__uri]", $publishForm).val(), exclude_uris ) == -1) {
								if ( $.inArray( channel_templates[$(this).val()]["channel_id"], include_channels_templates ) >= 0 ) {
									var template_id = channel_templates[$(this).val()]["template_id"];
									var template_text = $("select[name=structure__template_id] option[value=\'"+template_id+"\']", $publishForm).text();
									var r = confirm("Update template for this entry: \'"+template_text+"\'?");
									if (r == true) {
										$("select[name=structure__template_id], input[name=structure__template_id]", $publishForm).val(template_id);
									}
								}
							}
						});

						// Make sure structure URI is the same as the URL title
						if ( $.inArray( parseInt($("select[name=channel_id], input[name=channel_id]").val()), include_channels ) >= 0 ) {
							if ($("input[name=structure__uri]", $publishForm).val() != "/" && $.inArray( $("input[name=structure__uri]", $publishForm).val(), exclude_uris ) == -1) {
								//$("input[name=structure__uri]", $publishForm).val($("input[name=url_title]", $publishForm).val());
								$("input[name=url_title]", $publishForm).on("keyup keydown", function() {
									$("input[name=structure__uri]", $publishForm).val($(this).val());
								});
							}
						}

					});
				';

			}
		
		}
	
		return $data;
	}
	
	
}
// END CLASS

/* End of file */