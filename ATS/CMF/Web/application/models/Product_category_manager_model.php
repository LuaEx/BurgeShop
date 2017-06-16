<?php

class Product_category_manager_model extends CI_Model
{
	private $product_category_table_name="product_category";
	private $product_category_description_table_name="product_category_description";
	private $organized_product_category_file_path;
	private $hash_size=8;

	private $product_category_description_writable_props=array(
		'pcd_name','pcd_url','pcd_description','pcd_image','pcd_meta_keywords','pcd_meta_description'
	);

	public function __construct()
	{
		parent::__construct();

		$this->organized_product_category_file_path=CATEGORY_CACHE_DIR."/organized_product_cats.json";

      return;
   }


	public function install()
	{
		$tbl_name=$this->db->dbprefix($this->product_category_table_name); 
		$hash_size=$this->hash_size;

		$this->db->query(
			"CREATE TABLE IF NOT EXISTS $tbl_name (
				`pc_id` INT AUTO_INCREMENT
				,`pc_parent_id` INT DEFAULT 0
				,`pc_hash` CHAR($hash_size) DEFAULT NULL
				,`pc_sort_order` INT DEFAULT 0
				,`pc_show_in_list` BIT(1) DEFAULT 1
				,`pc_is_hidden` BIT(1) DEFAULT 0
				,PRIMARY KEY (pc_id)	
			) ENGINE=InnoDB DEFAULT CHARSET=utf8"
		);

		$tbl_name=$this->db->dbprefix($this->product_category_description_table_name); 
		$this->db->query(
			"CREATE TABLE IF NOT EXISTS $tbl_name (
				`pcd_category_id` INT NOT NULL
				,`pcd_lang_id` CHAR(2) NOT NULL
				,`pcd_name` VARCHAR(512)
				,`pcd_url` varchar(1024)
				,`pcd_description` TEXT
				,`pcd_image` varchar(1024)
				,`pcd_meta_keywords` VARCHAR(1024)
				,`pcd_meta_description` VARCHAR(1024)
				,PRIMARY KEY (pcd_category_id, pcd_lang_id)	
			) ENGINE=InnoDB DEFAULT CHARSET=utf8"
		);

		$this->load->model("module_manager_model");

		$this->module_manager_model->add_module("product_category","product_category_manager");
		$this->module_manager_model->add_module_names_from_lang_file("product_category");
		
		return;
	}

	public function uninstall()
	{
		return;
	}

	public function get_dashboard_info()
	{
		$CI=& get_instance();
		//$lang=$CI->language->get();
		//$CI->lang->load('ae_log',$lang);		
		
		$data=array();
		$row=$this->db
			->select("COUNT(*) as count")
			->from($this->product_category_table_name)
			->get()
			->row_array();
		$data['total']=$row['count'];
		
		$CI->load->library('parser');
		$ret=$CI->parser->parse($CI->get_admin_view_file("product_category_dashboard"),$data,TRUE);
		
		return $ret;		
	}

	public function sort_categories($cat_ids)
	{
		$update_array=array();
		$i=1;
		foreach($cat_ids as $cat_id)
			$update_array[]=array(
				"pc_id"=>$cat_id
				,"pc_sort_order"=>$i++
			);

		$this->db->update_batch($this->product_category_table_name,$update_array, "pc_id");

		$this->organize();
		
		$this->log_manager_model->info("PRODUCT_CATEGORY_RESORT",array("product_category_ids"=>implode(",",$cat_ids)));	

		return;
	}

	private function set_random_hash()
	{
		$cats=$this->db
			->select("product_category_id")
			->from($this->product_category_table_name)
			->where("ISNULL(pc_hash)",TRUE)
			->get()
			->result_array();

		if(!$cats)
			return FALSE;

		$logs=array();
		$update_array=array();

		foreach($cats as $cat)
		{
			$id=$cat['pc_id'];
			$hash=get_random_word($this->hash_size);

			$update_array[]=array(
				"pc_id"=>$id
				,"pc_hash"=>$hash
			);

			$logs["cat_".$id]=$hash;
		}

		$this->db->update_batch($this->product_category_table_name,$update_array, "pc_id");

		$this->log_manager_model->info("PRODUCT_CATEGORY_HASH_UPDATE",$logs);	

		return TRUE;
	}

	//this method is responsible for creating hierarchical structure of categories,
	//so we don't need to run multiplt queries to retreive the structure from the database.
	//it should be updated 
	public function organize()
	{
		//$this->set_random_hash();
		
		$result=$this->db
			->select("*")
			->from($this->product_category_table_name)
			->join($this->product_category_description_table_name,"pc_id = pcd_category_id","left")
			->order_by("pc_sort_order ASC, pc_id ASC, pcd_lang_id ASC")
			->get();

		$rows=$result->result_array();
		
		$cats=array();
		foreach($rows as $row)
		{
			$cid=$row['pc_id'];
			if(!isset($cats[$cid]))
			{
				$cats[$cid]=array();

				$cats[$cid]['id']=$cid;
				$cats[$cid]['show_in_list']=$row['pc_show_in_list'];
				$cats[$cid]['is_hidden']=$row['pc_is_hidden'];
				$cats[$cid]['hash']=$row['pc_hash'];
				$cats[$cid]['parents']=array();
				$cats[$cid]['parents'][0]=$row['pc_parent_id'];
				
				$cats[$cid]['names']=array();
				$cats[$cid]['urls']=array();
				$cats[$cid]['images']=array();
				$cats[$cid]['children']=array();
			}

			$cats[$cid]['names'][$row['pcd_lang_id']]=$row['pcd_name'];
			$cats[$cid]['urls'][$row['pcd_lang_id']]=$row['pcd_url'];
			$cats[$cid]['images'][$row['pcd_lang_id']]=$row['pcd_image'];
		}

		$cats[0]=array("id"=>0,"names"=>"ROOT","parents"=>array(0),'children'=>array());

		file_put_contents($this->organized_product_category_file_path, json_encode($cats));

		return;
	}

	public function get_all()
	{
		if(!file_exists($this->organized_product_category_file_path))
			$this->organize();

		$cats=json_decode(file_get_contents($this->organized_product_category_file_path),TRUE);

		foreach($cats as &$cat)
		{
			if(!$cat['id'])
				continue; 

			$parent=&$cats[$cat['parents'][0]];
			$parent['children'][]=&$cat;

			$i=0;
			$parent=&$cat;
			do
			{
				$parent_id=$parent['parents'][0];
				$parent=&$cats[$parent_id];

				$cat['parents'][$i]=$parent['id'];
				$i++;
			}
			while($parent['id']);
		}

		return $cats;
	}

	//includes name, url, and image of the requested categories
	public function get_categories_short_desc($cat_ids,$lang_id)
	{
		$ret=array();

		$all_cats=$this->get_all();
		foreach($cat_ids as $cid)
			if($cid)
				$ret[]=array(
					"name"=>$all_cats[$cid]['names'][$lang_id]
					,"url"=>get_customer_product_category_details_link($cid,$all_cats[$cid]['hash'],$all_cats[$cid]['urls'][$lang_id])
					,"image"=>$all_cats[$cid]['images'][$lang_id]
					,"is_hidden"=>$all_cats[$cid]['is_hidden']
				);


		return $ret;
	}


	//creates the hierarchy of categories
	//uses get_all
	//$type can be radio, or checkbox
	//$lang is the language selected for the name of each category
	//$ignore_ids are ids (and their children) which must not have radio or checkbox
	public function get_hierarchy($type,$lang,$ignore_ids=array())
	{
		$categories=$this->get_all();
		//bprint_r($categories);

		return "<ul>".$this->create_hierarchy($categories[0],$type,$lang,$ignore_ids)."</ul>";
	}

	private function create_hierarchy(&$category,$type,$lang,&$ignore_ids)
	{
		$id=$category['id'];
		if(in_array($id,$ignore_ids))
			$inp="&nbsp;";
		else
			switch($type)
			{
				case 'radio':
					$inp="<input type='radio' name='category' value='".$id."'/>";
					break;

				case 'checkbox':
					$inp="<input type='checkbox' data-id='".$id."' />";
					break;
			}

		if(!$id)
			$name="{root_text}";
		else
			if($category['names'][$lang])
				$name=$category['names'][$lang];
			else
				$name="{no_title_text}";
		

		$ret="<li>$inp <span data-id='$id'>$name</span>";

		
		if($category['children'])
		{	
			$ret.="<ul>";
			
			foreach($category['children'] as $child)
			{
				if(in_array($child['parents'][0],$ignore_ids))
					$ignore_ids[]=$child['id'];

				$ret.=$this->create_hierarchy($child,$type,$lang,$ignore_ids);
			}

			$ret.="</ul>";
		}
		
		$ret.="</li>";

		return $ret;
	}
	

	public function get_info($category_id,$lang_id=NULL)
	{
		$this->db
			->select("*")
			->from($this->product_category_table_name)
			->join($this->product_category_description_table_name,"pc_id = pcd_category_id","LEFT")
			->where("pc_id",(int)$category_id);

		if($lang_id)
			$this->db->where("pcd_lang_id",$lang_id);

		$result=$this->db
			->get()
			->result_array();

		if($lang_id)
			return $result[0];

		return $result;
	}

	public function add($parent_id)
	{
		$this->db->insert($this->product_category_table_name,array(
			"pc_parent_id"=>$parent_id
			,"pc_hash"=>get_random_word($this->hash_size)
		));
		
		$category_id=$this->db->insert_id();

		$category_descs=array();
		foreach($this->language->get_languages() as $index=>$lang)
			$category_descs[]=array(
				"pcd_category_id"=>$category_id
				,"pcd_lang_id"=>$index
			);

		$this->db->insert_batch($this->product_category_description_table_name,$category_descs);

		$this->log_manager_model->info("PRODUCT_CATEGORY_CREATE",array("pc_id"=>$category_id));	

		$this->organize();

		return $category_id;
	}

	//parentize of its sub-categories and posts 
	//to its parent ;)
	public function delete($category_id)
	{
		$row=$this->db
			->get_where($this->product_category_table_name,array("pc_id"=>$category_id))
			->row_array();

		$parent_id=$row['pc_parent_id'];
		
		$this->db
			->set("pc_parent_id",$parent_id)
			->where("pc_parent_id",$category_id)
			->update($this->product_category_table_name);

		$this->db
			->where("pc_id",$category_id)
			->delete($this->product_category_table_name);

		$this->db
			->where("pcd_category_id",$category_id)
			->delete($this->product_category_description_table_name);

		$this->load->model("product_manager_model");
		$this->product_manager_model->change_category($category_id,$parent_id);

		$this->log_manager_model->info("PROUCT_CATEGORY_DELETE",array("pc_id"=>$category_id));	

		$this->organize();

		return;
	}

	public function set_props($category_id,$category_props)
	{
		$log_props=array();

		$parent_id=$category_props['pc_parent_id'];
		$show_in_list=$category_props['pc_show_in_list'];
		$is_hidden=$category_props['pc_is_hidden'];
		$hash=$category_props['pc_hash'];

		$this->db
			->set("pc_parent_id",$parent_id)
			->set("pc_show_in_list",$show_in_list)
			->set("pc_is_hidden",$is_hidden)
			->set("pc_hash",$hash)
			->where("pc_id",$category_id)
			->update($this->product_category_table_name);

		$log_props["pc_parent_id"]=$parent_id;
		$log_props["pc_show_in_list"]=$show_in_list;
		$log_props["pc_is_hidden"]=$is_hidden;
		$log_props["pc_hash"]=$hash;

		foreach($category_props['descriptions'] as $category_description)
		{
			$lang=$category_description['pcd_lang_id'];

			$props=select_allowed_elements(
				$category_description,
				$this->product_category_description_writable_props
			);

			foreach($props as $prop => $value)
			{
				$this->db->set($prop,$value);
				$log_props[$lang."_".$prop]=$value;
			}

			$this->db
				->where("pcd_category_id",$category_id)
				->where("pcd_lang_id",$lang)
				->update($this->product_category_description_table_name);
		}

		$this->log_manager_model->info("PRODUCT_CATEGORY_CHANGE",$log_props);	

		$this->organize();

		return;
	}
}