<?php
/**
 * @brief		Group promotion node model
 * @author		<a href='https://www.invisioncommunity.com'>Invision Power Services, Inc.</a>
 * @copyright	(c) Invision Power Services, Inc.
 * @license		https://www.invisioncommunity.com/legal/standards/
 * @package		Invision Community
 * @since		4 Apr 2017
 */

namespace IPS\Member;

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( isset( $_SERVER['SERVER_PROTOCOL'] ) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * Group promotion node model
 */
class _GroupPromotion extends \IPS\Node\Model
{
	/**
	 * @brief	Database Table
	 */
	public static $databaseTable = 'core_group_promotions';
	
	/**
	 * @brief	Database Prefix
	 */
	public static $databasePrefix = 'promote_';
	
	/**
	 * @brief	Multiton Store
	 */
	protected static $multitons;
		
	/**
	 * @brief	[ActiveRecord] ID Database Column
	 */
	public static $databaseColumnId = 'id';
	
	/**
	 * @brief	[ActiveRecord] Multiton Map
	 */
	protected static $multitonMap	= array();
	
	/**
	 * @brief	[Node] Node Title
	 */
	public static $nodeTitle = 'grouppromotions';
	
	/**
	 * @brief	[Node] Sortable
	 */
	public static $nodeSortable = TRUE;
	
	/**
	 * @brief	[Node] Positon Column
	 */
	public static $databaseColumnOrder = 'position';

	/**
	 * @brief	[Node] Title prefix.  If specified, will look for a language key with "{$key}_title" as the key
	 */
	public static $titleLangPrefix = 'g_promotion_';
	
	/**
	 * @brief	[Node] Enabled/Disabled Column
	 */
	public static $databaseColumnEnabledDisabled = 'enabled';
	
	/**
	 * Form
	 *
	 * @param	\IPS\Helpers\Form	The form
	 * @return	void
	 */
	public function form( &$form )
	{
		$form->addHeader( 'generic_gp_details' );
		$form->add( new \IPS\Helpers\Form\Translatable( 'promote_title', NULL, TRUE, array( 'app' => 'core', 'key' => ( $this->id ? 'g_promotion_' . $this->id : NULL ) ) ) );
		$form->add( new \IPS\Helpers\Form\YesNo( 'promote_enabled', $this->id ? $this->enabled : 1, TRUE ) );

		/* Loop over our member filters */
		$form->addHeader( 'generic_gp_filters' );
		$options	= $this->id ? $this->_filters : array();

		$lastApp	= 'core';

		/* We take an extra step with groups to disable invalid options */
		$options['core_Group']['disabled_groups']	= $this->getDisabledGroups();

		foreach ( \IPS\Application::allExtensions( 'core', 'MemberFilter', TRUE, 'core' ) as $key => $extension )
		{
			if( method_exists( $extension, 'getSettingField' ) AND $extension->availableIn( 'group_promotions' ) )
			{
				/* See if we need a new form header - one per app */
				$_key		= explode( '_', $key );

				if( $_key[0] != $lastApp )
				{
					$lastApp	= $_key[0];
					$form->addHeader( $lastApp . '_bm_filters' );
				}

				/* Grab our fields and add to the form */
				$fields		= $extension->getSettingField( !empty( $options[ $key ] ) ? $options[ $key ] : array() );

				foreach( $fields as $field )
				{
					$form->add( $field );
				}
			}
		}

		$form->addHeader( 'generic_gp_actions' );
		$groups		= array_combine( array_keys( \IPS\Member\Group::groups( TRUE, FALSE ) ), array_map( function( $_group ) { return (string) $_group; }, \IPS\Member\Group::groups( TRUE, FALSE ) ) );
		$primary	= array( 0 => 'do_not_change_group' ) + $groups;

		/* And then allow the admin to choose which groups to promote to */
		$form->add( new \IPS\Helpers\Form\Radio( 'promote_group_primary', $this->id ? $this->_actions['primary_group'] : 0, FALSE, 
							array( 'options' => $primary ) ) );

		$form->add( new \IPS\Helpers\Form\CheckboxSet( 'promote_group_secondary', $this->id ? $this->_actions['secondary_group'] : array(), FALSE,
							array( 'options' => $groups, 'multiple' => true ) ) );

		$form->add( new \IPS\Helpers\Form\CheckboxSet( 'demote_group_secondary', $this->id ? $this->_actions['secondary_remove'] : array(), FALSE,
							array( 'options' => $groups, 'multiple' => true ) ) );
	}

	/**
	 * Return an array of groups that cannot be promoted
	 *
	 * @return array
	 */
	protected function getDisabledGroups()
	{
		$return = array( \IPS\Settings::i()->guest_group );

		foreach( \IPS\Member\Group::groups() as $group )
		{
			if( $group->g_promote_exclude )
			{
				$return[] = $group->g_id;
			}
		}

		return $return;
	}
	
	/**
	 * [Node] Format form values from add/edit form for save
	 *
	 * @param	array	$values	Values from the form
	 * @return	array
	 */
	public function formatFormValues( $values )
	{
		if ( !$this->id )
		{
			$this->save();
		}
		
		/* Save the title */
		\IPS\Lang::saveCustom( 'core', 'g_promotion_' . $this->id, $values['promote_title'] );

		/* Json-encode the rules */
		$_options	= array();

		/* Loop over bulk mail extensions to format the options */
		foreach ( \IPS\Application::allExtensions( 'core', 'MemberFilter', TRUE, 'core' ) as $key => $extension )
		{
			if( method_exists( $extension, 'save' ) AND $extension->availableIn( 'group_promotions' ) )
			{
				/* Grab our fields and add to the form */
				$_value		= $extension->save( $values );

				if( $_value )
				{
					$_options[ $key ]	= $_value;
				}
			}
		}

		$values['promote_filters'] = json_encode( $_options );

		/* Json-encode the actions */
		$values['promote_actions'] = json_encode( array( 
			'primary_group'		=> $values['promote_group_primary'],
			'secondary_group'	=> $values['promote_group_secondary'],
			'secondary_remove'	=> $values['demote_group_secondary']
		) );
		
		/* Now we have to remove any fields that aren't valid... */
		foreach( $values as $k => $v )
		{
			if( !in_array( $k, array( 'promote_enabled', 'promote_filters', 'promote_actions', 'promote_position' ) ) )
			{
				unset( $values[ $k ] );
			}
		}

		return parent::formatFormValues( $values );
	}

	/**
	 * Return our filters as an array
	 *
	 * @return array
	 */
	public function get__filters()
	{
		return json_decode( $this->filters, TRUE );
	}

	/**
	 * Return our actions as an array
	 *
	 * @return array
	 */
	public function get__actions()
	{
		return json_decode( $this->actions, TRUE );
	}

	/**
	 * Fetch All Root Nodes
	 *
	 * @param	string|NULL			$permissionCheck	The permission key to check for or NULl to not check permissions
	 * @param	\IPS\Member|NULL	$member				The member to check permissions for or NULL for the currently logged in member
	 * @param	mixed				$where				Additional WHERE clause
	 * @return	array
	 */
	public static function roots( $permissionCheck='view', $member=NULL, $where=array() )
	{
		if ( !count( $where ) )
		{
			$return = array();
			foreach( static::getStore() AS $node )
			{
				$return[ $node['promote_id'] ] = static::constructFromData( $node );
			}
			
			return $return;
		}
		else
		{
			return parent::roots( $permissionCheck, $member, $where );
		}
	}

	/**
	 * Set Enabled
	 *
	 * @return	void
	 */
	public function set__enabled( $enabled )
	{
		parent::set__enabled( $enabled );
		
		unset( \IPS\Data\Store::i()->group_promotions );
	}
	
	/**
	 * Get data store
	 *
	 * @return	array
	 * @note	Note that all records are returned, even disabled promotion rules. Enable status needs to be checked in userland code when appropriate.
	 */
	public static function getStore()
	{
		if ( !isset( \IPS\Data\Store::i()->group_promotions ) )
		{
			\IPS\Data\Store::i()->group_promotions = iterator_to_array( \IPS\Db::i()->select( '*', static::$databaseTable, NULL, "promote_position ASC" )->setKeyField( 'promote_id' ) );
		}
		
		return \IPS\Data\Store::i()->group_promotions;
	}

	/**
	 * Load Record
	 *
	 * @see		\IPS\Db::build
	 * @param	int|string	$id					ID
	 * @param	string		$idField			The database column that the $id parameter pertains to (NULL will use static::$databaseColumnId)
	 * @param	mixed		$extraWhereClause	Additional where clause(s) (see \IPS\Db::build for details)
	 * @return	static
	 * @throws	\InvalidArgumentException
	 * @throws	\OutOfRangeException
	 */
	public static function load( $id, $idField=NULL, $extraWhereClause=NULL )
	{
		if ( ( $idField === NULL or $idField === 'promote_id' ) and $extraWhereClause === NULL )
		{
			$promotions = static::getStore();
			if ( isset( $promotions[ $id ] ) )
			{
				return static::constructFromData( $promotions[ $id ] );
			}
			else
			{
				throw new \OutOfRangeException;
			}
		}
			
		return parent::load( $id, $idField, $extraWhereClause );		
	}
	
	/**
	 * [ActiveRecord] Delete
	 *
	 * @return	void
	 */
	public function delete()
	{
		parent::delete();
		
		unset( \IPS\Data\Store::i()->group_promotions );
	}
	
	/**
	 * [ActiveRecord] Save Changed Columns
	 *
	 * @return	void
	 */
	public function save()
	{
		parent::save();
		
		unset( \IPS\Data\Store::i()->group_promotions );
	}

	/**
	 * @brief	Cache extensions so we only need to load them once
	 */
	protected static $extensions = NULL;

	/**
	 * Check if a member matches the rule
	 *
	 * @param	\IPS\Member		$member	Member to check
	 * @return	bool
	 */
	public function matches( \IPS\Member $member )
	{
		if( static::$extensions === NULL )
		{
			static::$extensions = \IPS\Application::allExtensions( 'core', 'MemberFilter', TRUE, 'core' );
		}

		/* Loop over the filters */
		foreach( $this->_filters as $key => $filter )
		{
			if( isset( static::$extensions[ $key ] ) )
			{
				/* Ask the extension if this member matches the defined rule...if not, just return FALSE now */
				if( !static::$extensions[ $key ]->matches( $member, $filter ) )
				{
					return FALSE;
				}
			}
		}

		/* If we are still here, then the rule matched */
		return TRUE;
	}
}