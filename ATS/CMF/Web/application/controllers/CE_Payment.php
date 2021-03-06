<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

class CE_Payment extends Burge_CMF_Controller {
	protected $hit_level=1;

	function __construct()
	{
		parent::__construct();

		$this->load->model(array(
			"customer_manager_model"
			,"order_manager_model"
			,"payment_manager_model"
		));
	}

	public function pay($order_id, $ops_number)
	{	
		if(!$this->customer_manager_model->has_customer_logged_in())
		{
			redirect(get_link("home_url"));
			return;
		}

		$order_id=(int)$order_id;
		$order=$this->order_manager_model->get_order_payment_section($order_id, $ops_number);
		if(!$order || ($order['ops_status'] !== 'not_payed'))
			return redirect(get_link("home_url"));
		
		$this->data['order_id']=$order_id;

		$this->data['message']=get_message();
		$this->data['order_total']=$order['ops_total'];

		$payment_methods=$this->payment_manager_model->get_payment_methods();
		$payments=array();
		foreach($payment_methods as $p)
		{
			$link=get_customer_payment_method_link($order_id, $ops_number, $p);
			$name=$this->lang->line("payment_method_".$p);
			$image_link="/payment/".$p.".png";
			if(file_exists(IMAGES_DIR.$image_link))
				$image=get_link("images_url").$image_link;
			else
				$image=get_link("images_url")."/null.png";

			$payments[]=array(
				"link"	=> $link
				,"name"	=> $name
				,"image"	=> $image
			);
		}
		$this->data['payment_methods']=$payments;

		$this->data['lang_pages']=get_lang_pages(get_customer_order_section_payment_link($order_id, $ops_number, TRUE));
		$this->data['header_title']=
			$this->lang->line("payment").$this->lang->line("header_separator")
			.$this->lang->line("order")." ".$order_id.$this->lang->line("header_separator")
			.$this->data['header_title'];

		$this->send_customer_output("payment_pay");
		
		return;
	}
}