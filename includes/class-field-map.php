<?php

/**
 * Class MC4WP_Field_Map
 *
 * @internal
 * @since 2.x
 * @ignore
 */
class MC4WP_Field_Map {

	/**
	 * @var array
	 */
	protected $data = array();

	/**
	 * @var array
	 */
	protected $leftover_data = array();

	/**
	 * @var array
	 */
	protected $lists = array();

	/**
	 * @var MC4WP_MailChimp
	 */
	protected $mailchimp;

	/**
	 * @var array|bool
	 */
	public $list_fields = array();

	/**
	 * @var array
	 */
	public $custom_fields = array();

	/**
	 * @var array
	 */
	public $global_fields = array();


	/**
	 * @param array $data
	 * @param array $lists
	 */
	public function __construct( array $data, array $lists ) {
		$this->data = $data;
		$this->leftover_data = $data;
		$this->lists = $lists;
		$this->mailchimp = new MC4WP_MailChimp();
		$this->list_fields = $this->map_lists();
		$this->global_fields = $this->map_global_fields();
		$this->custom_fields = $this->find_custom_fields();
	}

	/**
	 * @return array|bool
	 */
	public function map_lists() {
		$map = array();

		foreach( $this->lists as $list_id ) {
			$map[ $list_id ] = $this->map_list( $list_id );
		}

		$map = array_filter( $map );
		return $map;
	}

	/**
	 * @param $list_id
	 *
	 * @return array
	 * @throws Exception
	 */
	public function map_list( $list_id ) {
		$list = $this->mailchimp->get_list( $list_id, true );

		// skip this list if it's unexisting
		if( ! is_object( $list ) || ! isset( $list->merge_vars ) ) {
			return array();
		}

		$map = array();

		// loop through list fields
		foreach( $list->merge_vars as $field ) {
			$map[ $field->tag ] = $this->map_list_field( $field );
		}

		// loop through list interest groupings
		if( ! empty( $list->interest_groupings ) ) {
			$map['GROUPINGS'] = array_map( array ($this, 'map_list_grouping' ), $list->interest_groupings );
			$map['GROUPINGS'] = array_filter( $map['GROUPINGS'] );
		}

		$map = array_filter( $map );

		return $map;
	}

	/**
	 * @param $field
	 *
	 * @return mixed
	 */
	private function map_list_field( $field ) {

		// if field is not set, continue.
		// don't use empty here as empty fields are perfectly valid (for non-required fields)
		if( ! isset( $this->data[ $field->tag ] ) ) {
			return false;
		}

		// grab field value from data
		$value = $this->data[ $field->tag ];
		unset( $this->leftover_data[ $field->tag ] );

		// format field value according to its type
		$value = $this->format_field_value( $value, $field->field_type );

		return $value;
	}

	/**
	 * @param $grouping
	 *
	 * @return array|null
	 */
	public function map_list_grouping( $grouping ) {
		// check if data for this group was sent
		if( ! empty( $this->data['GROUPINGS'][$grouping->id] ) ) {
			$groups = $this->data['GROUPINGS'][$grouping->id];
		} elseif( ! empty( $this->data['GROUPINGS'][$grouping->name] ) ) {
			$groups = $this->data['GROUPINGS'][$grouping->name];
		} else {
			return null;
		}

		unset( $this->leftover_data['GROUPINGS'] );

		// make sure groups is an array
		if( ! is_array( $groups ) ) {
			$groups = explode( ',', $groups );
		}

		$groups = array_map( 'sanitize_text_field', $groups );

		// format new grouping
		return array(
			'id' => $grouping->id,
			'groups' => $groups,
		);
	}

	/**
	 * @return array
	 */
	public function find_custom_fields() {

		if( empty( $this->leftover_data ) ) {
			return array();
		}

		$custom_fields = array();

		foreach( $this->leftover_data as $field => $value ) {

			if( $this->is_internal_field( $field ) ) {
				continue;
			}

			$custom_fields[ $field ] = $value;
		}

		return $custom_fields;
	}

	/**
	 * @return array
	 */
	private function map_global_fields() {
		$global_fields = array();

		// map global fields
		$global_field_names = array(
			'MC_LOCATION',
			'MC_NOTES',
			'MC_LANGUAGE'
		);

		foreach( $global_field_names as $field_name => $field_type ) {
			if( isset( $this->data[ $field_name ] ) ) {
				$global_fields[ $field_name ] = $this->data[ $field_name ];
			}
		}

		return $global_fields;
	}

	/**
	 * Format field value according to its type
	 *
	 * @param $field_type
	 * @param $field_value
	 *
	 * @return array|string
	 */
	protected function format_field_value( $field_value, $field_type ) {

		$field_type = strtolower( $field_type );

		switch( $field_type ) {

			// birthday fields need to be MM/DD for the MailChimp API
			case 'birthday':
				$field_value = (string) date( 'm/d', strtotime( $field_value ) );
				break;

			case 'address':

				// auto-format if this is a string
				if( is_string( $field_value ) ) {

					// addr1, addr2, city, state, zip, country
					$address_pieces = explode( ',', $field_value );

					// try to fill it.... this is a long shot
					$field_value = array(
						'addr1' => $address_pieces[0],
						'city'  => ( isset( $address_pieces[1] ) ) ?   $address_pieces[1] : '',
						'state' => ( isset( $address_pieces[2] ) ) ?   $address_pieces[2] : '',
						'zip'   => ( isset( $address_pieces[3] ) ) ?   $address_pieces[3] : ''
					);
				} elseif( is_array( $field_value ) ) {

					// merge with array of empty defaults to allow skipping certain fields
					$default_address = array(
						'addr1' => '',
						'city' => '',
						'state' => '',
						'zip' => ''
					);

					$field_value = array_merge( $default_address, $field_value );
				}

				break;
		}

		/**
		 * Filters the value of a field after it is formatted.
		 *
		 * Use this to format a field value according to the field type (in MailChimp).
		 *
		 * @param string $field_value
		 * @param string $field_type
		 */
		$field_value = apply_filters( 'mc4wp_format_field_value', $field_value, $field_type );

		return $field_value;
	}

	/**
	 * @param $field
	 *
	 * @return bool
	 */
	public function is_not_internal_field( $field ) {
		return ! $this->is_internal_field( $field );
	}

	/**
	 * @param $field
	 *
	 * @return bool
	 */
	public function is_internal_field( $field ) {

		if( empty( $field ) || $field[0] === '_' ) {
			return true;
		}

		// Ignore those fields, we don't need them
		$ignored_vars = array( 'CPTCH_NUMBER', 'CNTCTFRM_CONTACT_ACTION', 'CPTCH_RESULT', 'CPTCH_TIME', 'MC4WP_ACTION' );
		if( in_array( $field, $ignored_vars ) ) {
			return true;
		}

		return false;
	}

}