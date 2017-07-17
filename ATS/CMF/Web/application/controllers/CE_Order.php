<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

class CE_Order extends Burge_CMF_Controller {
	protected $hit_level=1;

	function __construct()
	{
		parent::__construct();

		$this->load->model(array(
			"cart_manager_model"
			,"customer_manager_model"
			,"order_manager_model"
		));

		$this->lang->load('ce_order',$this->selected_lang);
	}

	public function submit()
	{	
		$cart=$this->cart_manager_model->get_cart($this->selected_lang);
		if(!$cart['products'])
			return redirect(get_link("home_url"));

		if(!$this->customer_manager_model->has_customer_logged_in())
		{
			$this->session->set_userdata("backurl",get_link("customer_order_submit"));
			set_message($this->lang->line("please_login_before_checkout"));
			redirect(get_link("customer_login"));
			return;
		}

		$order_id=$this->order_manager_model->submit_order();

		set_message($this->lang->line("your_order_submitted_successfully"));

		redirect(get_customer_payment_order_link($order_id));
		
		return;
	}

	public function orders_list()
	{	
		if(!$this->customer_manager_model->has_customer_logged_in())
		{
			$this->session->set_userdata("backurl",get_link("customer_order"));
			redirect(get_link("customer_login"));
			return;
		}

		$this->set_search_results();

		$this->data['message']=get_message();

		$this->data['raw_page_url']=get_link("customer_order");
		$this->data['lang_pages']=get_lang_pages(get_link("customer_order",TRUE));
		$this->data['header_title']=$this->lang->line("orders").$this->lang->line("header_separator").$this->data['header_title'];
		
		$this->send_customer_output("order");

		return;	 
	}

	private function set_search_results()
	{
		$customer_id=$this->customer_manager_model->get_logged_customer_id();
		$filter=array("customer_id" => $customer_id);
		
		$this->data['filter']=$filter;
		
		$items_per_page=10;
		$page=1;
		if($this->input->get("page"))
			$page=(int)$this->input->get("page");

		$total=$this->order_manager_model->get_total_orders($filter);
		$this->data['total']=$total;
		$this->data['total_pages']=ceil($total/$items_per_page);
		if($total)
		{
			if($page > $this->data['total_pages'])
				$page=$this->data['total_pages'];
			if($page<1)
				$page=1;
			$this->data['current_page']=$page;
			
			$start=($page-1)*$items_per_page;
			$filter['start']=$start;
			$filter['length']=$items_per_page;

			$end=$start+$items_per_page-1;
			if($end>($total-1))
				$end=$total-1;
			$this->data['start']=$start+1;
			$this->data['end']=$end+1;		
	
			$filter['order_by']="order_id DESC";

			$this->data['orders_info']=$this->order_manager_model->get_orders($filter);

			unset($filter['start'],$filter['length'],$filter['order_by']);
		}
		else
		{
			$this->data['start']=0;
			$this->data['end']=0;
			$this->data['orders_info']=array();
		}

		return;
	}

	public function details($order_id)
	{
		if(!$this->customer_manager_model->has_customer_logged_in())
		{
			$this->session->set_userdata("backurl",get_link("customer_order"));
			redirect(get_link("customer_login"));
			return;
		}

		$customer_id=$this->customer_manager_model->get_logged_customer_id();
		$order_id=(int)$order_id;

		$this->load->model(array(
			"cart_manager_model"
			,"payment_manager_model"
		));

		$orders_info=$this->order_manager_model->get_orders(array(
			"order_id"			=> $order_id
			,"customer_id"		=> $customer_id
		));

		if(!$orders_info)
			return redirect(get_link('admin_order'));

		$this->data['order_id']=$order_id;

		$this->data['order_info']=$orders_info[0];

		$this->data['cart_info']=$this->cart_manager_model->get_order_cart($order_id, $this->selected_lang);

		$this->data['message']=get_message();
		$this->data['lang_pages']=get_lang_pages(get_customer_order_details_link($order_id,TRUE));
		$this->data['header_title']=$this->lang->line("order_details")." ".$order_id
			.$this->lang->line("header_separator").$this->data['header_title'];
		
		$this->send_customer_output("order_details");

		return;
	}
}