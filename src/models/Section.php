<?php
namespace Blocks;

/**
 *
 */
class Section extends Model
{
	protected $tableName = 'sections';
	protected $hasBlocks = true;

	protected $attributes = array(
		'name'        => AttributeType::Name,
		'handle'      => AttributeType::Handle,
		'max_entries' => array('type' => AttributeType::TinyInt, 'unsigned' => true),
		'has_urls'    => array('type' => AttributeType::Boolean, 'default' => true),
		'url_format'  => AttributeType::Varchar,
		'template'    => AttributeType::Template,
		'sortable'    => AttributeType::Boolean
	);

	protected $belongsTo = array(
		'parent' => array('model' => 'Section'),
		'site'   => array('model' => 'Site', 'required' => true)
	);

	protected $hasMany = array(
		'children' => array('model' => 'Section', 'foreignKey' => 'parent'),
		'entries'  => array('model' => 'Entry', 'foreignKey' => 'section')
	);

	protected $indexes = array(
		array('columns' => array('site_id', 'handle'), 'unique' => true),
	);

}
