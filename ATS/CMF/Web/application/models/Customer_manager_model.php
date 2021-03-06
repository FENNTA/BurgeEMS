<?php
class Customer_manager_model extends CI_Model
{
	private $customer_table_name="customer";
	private $customer_event_table_name="customer_event";
	private $customer_types=array("student","parent","teacher");

	private $event_types=array(/*"has_news","verification",*/"has_message");

	private $customer_props_can_be_written=array(
		"customer_type"
		,"customer_email"
		,"customer_name"
		,"customer_code" 
		,"customer_father_code" 
		,"customer_mother_code" 
		,"customer_province"
		,"customer_city"
		,"customer_address"
		,"customer_phone" 
		,"customer_mobile"
		,"customer_active"
		,"customer_class_id"
		,"customer_image_name"
		,"customer_birthday"
		,"customer_subject"
		,"customer_image_hash"
		,"customer_order"
	);

	private $customer_props_can_be_read=array(
		"customer_id"
		,"customer_type"
		,"customer_email"
		,"customer_name"
		,"customer_code" 
		,"customer_father_code" 
		,"customer_mother_code" 
		,"customer_province"
		,"customer_city"
		,"customer_address"
		,"customer_phone" 
		,"customer_mobile"
		,"customer_active"
		,"customer_class_id"
		,"customer_image_name"
		,"customer_birthday"
		,"customer_subject"
		,"customer_image_hash"
	);

	private $customer_log_dir;
	private $customer_log_file_extension="txt";
	private $customer_log_types=array(
		"UNKOWN"									=>0

		,"FILE_DIR_CREATE"		=>201
		,"FILE_DIR_DELETE"		=>202
		,"FILE_DIR_COPY"			=>203
		,"FILE_DIR_MOVE"			=>204
		,"FILE_DIR_RENAME"		=>205
		,"FILE_DIR_DOWNLOAD"		=>206		
		,"FILE_FILE_UPLOAD"		=>207
		,"FILE_FILE_DELETE"		=>208
		,"FILE_FILE_COPY"			=>209
		,"FILE_FILE_MOVE"			=>210
		,"FILE_FILE_RENAME"		=>211

		,"CUSTOMER_ADD"						=>1001
		,"CUSTOMER_INFO_CHANGE"				=>1002
		,"CUSTOMER_TASK_EXEC"				=>1003
		,"CUSTOMER_TASK_MANAGER_NOTE"		=>1004
		,"CUSTOMER_LOGIN"						=>1005
		,"CUSTOMER_LOGOUT"					=>1006
		,"CUSTOMER_PASS_CHANGE"				=>1007
		,"CUSTOMER_SET_EVENT"				=>1008
		,"CUSTOMER_UNSET_EVENT"				=>1009

		,"MESSAGE_ADD"							=>1131

		,"REWARD_ADD"						=> 2071
		,"REWARD_SET_PRIZE_ACCESS"		=> 2072
		,"REWARD_EDIT"						=> 2073

		,"QUESTION_COLLECTION_ADD"		=> 2101

		,"CLASS_POST_ADD"					=> 2131
		,"CLASS_POST_CHANGE"				=> 2132
		,"CLASS_POST_DELETE"				=> 2133
		,"CLASS_POST_COMMENT"			=> 2134
		,"CLASS_POST_VERIFY"				=> 2135
	);
	
	public function __construct()
	{
		parent::__construct();

		$this->customer_log_dir=HOME_DIR."/application/logs/customer";
		
		return;
	}

	public function install()
	{
		$table=$this->db->dbprefix($this->customer_table_name); 
		$customer_types="'".implode("','", $this->customer_types)."'";
		$default_type="'".$this->customer_types[0]."'";

		$this->db->query(
			"CREATE TABLE IF NOT EXISTS $table (
				`customer_id` int AUTO_INCREMENT NOT NULL
				,`customer_type` enum($customer_types) DEFAULT $default_type
				,`customer_email` varchar(100) NOT NULL 
				,`customer_pass` char(32) DEFAULT NULL
				,`customer_salt` char(32) DEFAULT NULL
				,`customer_name` varchar(255) DEFAULT NULL
				,`customer_code` char(10) DEFAULT NULL
				,`customer_father_code` char(10) DEFAULT NULL
				,`customer_mother_code` char(10) DEFAULT NULL
				,`customer_province` int DEFAULT 0
				,`customer_city` int DEFAULT 0
				,`customer_address` varchar(1000) DEFAULT NULL
				,`customer_phone` varchar(32) DEFAULT NULL 
				,`customer_mobile` varchar(32) DEFAULT NULL 
				,`customer_active` bit(1) DEFAULT 1
				,`customer_class_id` int DEFAULT 0
				,`customer_image_name` char(15) DEFAULT NULL
				,`customer_birthday` char(10)	DEFAULT NULL
				,`customer_subject` varchar(64) DEFAULT NULL
				,`customer_image_hash` char(5) DEFAULT NULL
				,`customer_order` int default 1
				,PRIMARY KEY (customer_id)	
			) ENGINE=InnoDB DEFAULT CHARSET=utf8"
		);

		$table=$this->db->dbprefix($this->customer_event_table_name); 
		$this->db->query(
			"CREATE TABLE IF NOT EXISTS $table (
				`ce_customer_id` INT NOT NULL
				,`ce_event_type` VARCHAR(100) NOT NULL
				,`ce_timestamp` DATETIME NOT NULL
				,PRIMARY KEY (ce_customer_id, ce_event_type)	
			) ENGINE=InnoDB DEFAULT CHARSET=utf8"
		);

		if(make_dir_and_check_permission($this->customer_log_dir)<0)
		{
			echo "Error: ".$this->customer_log_dir." cant be used, please check permissions, and try again";
			exit;
		}

		$this->load->model("module_manager_model");

		$this->module_manager_model->add_module("customer","customer_manager");
		$this->module_manager_model->add_module_names_from_lang_file("customer");
		
		$this->insert_province_and_citiy_tables_to_db();

		$this->load->model("constant_manager_model");
		//these important keys should be set to 0 by functions called periodially
		//may be in the daily result function
		$this->constant_manager_model->set("allow_user_login_to_customer_account",0);

		$this->constant_manager_model->set("allow_customer_new_password",0);
		$this->constant_manager_model->set("allow_customer_bulk_new_password",0);
		
		return;
	}

	public function uninstall()
	{
		
		return;
	}

	public function delete_not_found_images()
	{
		$rows=$this->db
			->select("*")
			->from($this->customer_table_name)
			->get()
			->result_array();

		$ids=array();
		foreach($rows as $row)
			if($row['customer_image_hash'])
				if(!file_exists(get_customer_image_path($row['customer_id'],$row['customer_image_hash'])))
					$ids[]=$row['customer_id'];

		if($ids)
			$this->db
				->set("customer_image_hash",NULL)
				->where_in("customer_id",$ids)
				->update($this->customer_table_name);

		return;
	}

	public function deactivate_student($customer_id)
	{
		$row=$this->db
			->select("customer_father_code, customer_mother_code")
			->from($this->customer_table_name)
			->where("customer_id", $customer_id)
			->get()
			->row_array();

		if(!$row)
			return;

		$father_code=trim($row['customer_father_code']);
		$mother_code=trim($row['customer_mother_code']);

		$this->db
			->set("customer_active",0)
			->where("customer_id", $customer_id)
			->update($this->customer_table_name);

		if($father_code)
		{
			$res=$this->db
				->select("COUNT(*) as count")
				->from($this->customer_table_name)
				->where("customer_father_code",$father_code)
				->where("customer_active","1")
				->get()
				->row_array();
	
			if(!$res['count'])
				$this->db
					->set("customer_active",0)
					->where("customer_code", $father_code)
					->update($this->customer_table_name);
		}

		if($mother_code)
		{
			$res=$this->db
				->select("COUNT(*) as count")
				->from($this->customer_table_name)
				->where("customer_mother_code",$mother_code)
				->where("customer_active","1")
				->get()
				->row_array();

			if(!$res['count'])	
				$this->db
					->set("customer_active",0)
					->where("customer_code", $mother_code)
					->update($this->customer_table_name);
		}
		
		return;
	}

	public function get_parents($filter)
	{
		$this->db->select("main.customer_id, main.customer_name, main.customer_code");
		$this->db->from($this->customer_table_name." main");
		
		$this->db->join(
			$this->customer_table_name." child" 
			,"main.customer_code = child.customer_father_code OR main.customer_code = child.customer_mother_code"
			,"INNER");

		if(isset($filter['child_class_id_in']))
		{
			$this->db->where("child.customer_active",1);
			$this->db->where_in("child.customer_class_id",$filter['child_class_id_in']);
		}

		if(isset($filter['name']))
		{
			$filter['name']=persian_normalize($filter['name']);
			$this->db->where("main.customer_name LIKE '%".str_replace(' ', '%', $filter['name'])."%'");
		}

		if(isset($filter['type']))
		{
			$this->db->where("main.customer_type",$filter['type']);
		}

		if(isset($filter['active']))
		{
			$this->db->where("main.customer_active",$filter['active']);
		}

		if(isset($filter['start']) && isset($filter['length']))
			$this->db->limit((int)$filter['length'],(int)$filter['start']);		

		$this->db->group_by("main.customer_id");
		
		$query=$this->db->get();

		$results=$query->result_array();

		return $results;
	}

	public function get_total_customers($filter=array())
	{
		$this->db->select("COUNT(*) as count");
		$this->db->from($this->customer_table_name);
		$this->set_search_where_clause($filter);

		$query=$this->db->get();

		$row=$query->row_array();

		return $row['count'];
	}

	public function get_customers($filter)
	{
		$this->db->select($this->customer_props_can_be_read);
		$this->db->from($this->customer_table_name);

		$this->set_search_where_clause($filter);

		$query=$this->db->get();

		$results=$query->result_array();

		return $results;
	}

	private function set_search_where_clause($filter)
	{
		if(isset($filter['name']))
		{
			$filter['name']=persian_normalize($filter['name']);
			$this->db->where("customer_name LIKE '%".str_replace(' ', '%', $filter['name'])."%'");
		}

		if(isset($filter['type']))
		{
			$this->db->where("customer_type",$filter['type']);
		}

		if(isset($filter['active']))
		{
			$this->db->where("customer_active",$filter['active']);
		}

		if(isset($filter['class_id']))
		{
			$this->db->where("customer_class_id",$filter['class_id']);
		}

		if(isset($filter['class_id_in']))
		{
			$this->db->where_in("customer_class_id",$filter['class_id_in']);
		}

		if(isset($filter['email']))
		{
			$filter['email']=persian_normalize($filter['email']);
			$this->db->where("customer_email LIKE '%".str_replace(' ', '%', $filter['email'])."%'");
		}

		if(isset($filter['code']))
		{
			$filter['code']=persian_normalize($filter['code']);
			$this->db->where("customer_code LIKE '%".trim($filter['code'])."%'");
		}

		if(isset($filter['province']))
		{
			$filter['province']=persian_normalize($filter['province']);
			$this->db->where("customer_province = ".(int)$filter['province']."");
		}
		if(isset($filter['city']))
		{
			$filter['city']=persian_normalize($filter['city']);
			$this->db->where("customer_city = ".(int)$filter['city']."");
		}
		if(isset($filter['address']))
		{
			$filter['address']=persian_normalize($filter['address']);
			$this->db->where("customer_address LIKE '%".str_replace(' ', '%', $filter['address'])."%'");
		}
		if(isset($filter['phone_mobile']))
		{
			$filter['phone_mobile']=persian_normalize($filter['phone_mobile']);
			$this->db->where(
				"(
					(customer_phone LIKE '%".str_replace(' ', '%', $filter['phone_mobile'])."%' ) OR
					(customer_mobile LIKE '%".str_replace(' ', '%', $filter['phone_mobile'])."%' )
				)"
			);
		}

		if(isset($filter['id']))
		{
			$this->db->where_in("customer_id",$filter['id']);
		}

		if(isset($filter['order_by']))
			$this->db->order_by($filter['order_by']);

		if(isset($filter['start']) && isset($filter['length']))
			$this->db->limit((int)$filter['length'],(int)$filter['start']);


		return;
	}

	public function get_customer_info($customer_id)
	{
		$results=$this->get_customers(array("id"=>array($customer_id)));

		if(isset($results[0]))
			return $results[0];

		return NULL;
	}

	public function get_dashboard_info()
	{
		$CI=& get_instance();
		$lang=$CI->language->get();
		$CI->lang->load('ae_customer',$lang);		
		
		$data['total_text']=$this->lang->line("total");
		$data['customers_count']=$this->get_total_customers();
		
		$CI->load->library('parser');
		$ret=$CI->parser->parse($CI->get_admin_view_file("customer_dashboard"),$data,TRUE);
		
		return $ret;		
	}

	public function get_customer_event_types()
	{
		return $this->event_types;
	}

	public function get_customer_events($customer_id)
	{
		$this->db->from($this->customer_event_table_name);
		$this->db->where("ce_customer_id",$customer_id);
		$this->db->order_by("ce_event_type ASC");
		$result=$this->db->get();

		return $result->result_array();
	}

	public function set_customer_event($customer_id,$event_name)
	{
		$now=get_current_time();

		$ins=array(
			"ce_customer_id"=>$customer_id
			,"ce_event_type"=>$event_name
			,"ce_timestamp"=>$now
		);

		$this->db->replace($this->customer_event_table_name,$ins);

		unset($ins['ce_timestamp']);

		$this->log_manager_model->info("CUSTOMER_SET_EVENT",$ins);
		$this->add_customer_log($customer_id,'CUSTOMER_SET_EVENT',$ins);

		return TRUE;
	}

	public function set_customer_events($customer_id,$events)
	{
		$this->db->from($this->customer_event_table_name);
		$this->db->where("ce_customer_id",$customer_id);
		$this->db->delete();

		if($events)
		{
			$now=get_current_time();

			$ins=array();
			foreach($events as $ev)
				$ins[]=array(
					"ce_customer_id"=>$customer_id
					,"ce_event_type"=>$ev
					,"ce_timestamp"=>$now
				);
			$this->db->insert_batch($this->customer_event_table_name,$ins);
		}

		$props=array();
		$props['customer_id']=$customer_id;
		$props['events']=implode(" , ",$events);
		
		$this->log_manager_model->info("CUSTOMER_SET_EVENT",$props);

		$this->add_customer_log($customer_id,'CUSTOMER_SET_EVENT',$props);

		return;		
	}

	public function get_customer_types()
	{
		return $this->customer_types;
	}

	public function add_customer($props_array,$desc="",$login=FALSE)
	{	
		//in BurgeEMS, there is no registeration in customer env.
		$login=FALSE;

		$desc=persian_normalize_word($desc);
		
		if(!isset($props_array['customer_type']) || !in_array($props_array['customer_type'], $this->customer_types))
			$props_array['customer_type']=$this->customer_types[0];

		$props=select_allowed_elements($props_array,$this->customer_props_can_be_written);
		persian_normalize($props);

		if(isset($props['customer_email']) && $props['customer_email'])
		{
			$this->db->select("count(customer_id) as count");
			$this->db->from($this->customer_table_name);
			$this->db->where("customer_email",$props['customer_email']);
			$result=$this->db->get();
			$row=$result->row_array();
			$count=$row['count'];
			if($count)
				return FALSE;

			if(0)
			if(!isset($props['customer_name']) || !$props['customer_name'])
				$props['customer_name']=$props['customer_email'];
		}

		if(isset($props['customer_code']) && $props['customer_code'])
		{
			$this->db->select("count(customer_id) as count");
			$this->db->from($this->customer_table_name);
			$this->db->where("customer_code",$props['customer_code']);
			$result=$this->db->get();
			$row=$result->row_array();
			$count=$row['count'];
			if($count)
				return FALSE;
		}
				
		$this->db->insert($this->customer_table_name,$props);
		$id=$this->db->insert_id();

		$props['desc']=$desc;
		$this->add_customer_log($id,'CUSTOMER_ADD',$props);
		
		$props['customer_id']=$id;
		$this->log_manager_model->info("CUSTOMER_ADD",$props);

		//in BurgeEMS, we don't create passwords automatically
		if(0)
		//in ATS, we should send an email to the customer
		if(isset($props['customer_email']) && $props['customer_email'])
		{
			$pass=$this->set_new_password($props['customer_email']);
			$this->send_registeration_mail($props['customer_email'],$pass);

			if($login)
				$this->login($props['customer_email'],$pass);
		}

		return $id;
	}

	public function sort($ids)
	{
		$update_array=array();
		$i=1;
		foreach(explode(",",$ids) as $id)
			$update_array[]=array(
				"customer_id"		=> $id
				,"customer_order"	=> $i++
			);

		$this->db->update_batch($this->customer_table_name,$update_array, "customer_id");
		
		$this->log_manager_model->info("CUSTOMER_RESORT",array("class_ids"=>$ids));	

		return;
	}


	public function get_customer_id_with_customer_email($customer_email)
	{
		$this->db->select("customer_id");
		$this->db->from($this->customer_table_name);
		$this->db->where("customer_email",$customer_email);
		$result=$this->db->get();
		$row=$result->row_array();
		
		return $row['customer_id'];
	}

	//returns 0 if every thing is  OK
	//returns -1 if customer_name is NULL
	//returns -2 if customer_email is used by antoher id
	//returns -3 if new customer_email is NULL 
	//returns -4 if customer_code is used by another id
	public function set_customer_properties($customer_id, $props_array, $desc)
	{
		$props=select_allowed_elements($props_array,$this->customer_props_can_be_written);
		persian_normalize($props);
		$should_send_registeration_mail=FALSE;

		if(isset($props['customer_name']) && !$props['customer_name'])
			return -1;

		if(isset($props['customer_email']))
		{
			if($props['customer_email'])
			{
				$this->db->select("count(customer_id) as count");
				$this->db->from($this->customer_table_name);
				$this->db->where("customer_id !=",$customer_id);
				$this->db->where("customer_email",$props['customer_email']);
				$result=$this->db->get();
				$row=$result->row_array();
				$count=$row['count'];
				if($count)
					return -2;
			}

			$this->db->select("customer_email");
			$result=$this->db->get_where($this->customer_table_name,array("customer_id"=>$customer_id));
			$row=$result->row_array();

			if($row['customer_email'])
			{
				if(!$props['customer_email'])
					return -3;
			}
			else
				if($props['customer_email'])
					$should_send_registeration_mail=TRUE;
		}

		if(isset($props['customer_code']) && $props['customer_code'])
		{
			$this->db->select("count(customer_code) as count");
			$this->db->from($this->customer_table_name);
			$this->db->where("customer_id !=",$customer_id);
			$this->db->where("customer_code",$props['customer_code']);
			$result=$this->db->get();
			$row=$result->row_array();
			$count=$row['count'];
			if($count)
				return -4;
		}

		$this->db->where("customer_id",(int)$customer_id);
		$this->db->update($this->customer_table_name,$props);

		$props['customer_id']=$customer_id;
		$props['desc']=$desc;

		$this->log_manager_model->info("CUSTOMER_INFO_CHANGE",$props);

		$this->add_customer_log($customer_id,'CUSTOMER_INFO_CHANGE',$props);

		//in BurgeEMS, we don't send password automatically
		if(0)
		if($should_send_registeration_mail)
		{
			$pass=$this->set_new_password($props['customer_email']);
			$this->send_registeration_mail($props['customer_email'],$pass);
		}

		return 0;
	}

	public function get_customer_log_types()
	{
		return $this->customer_log_types;
	}

	public function get_task_exec_file_path($customer_id,$file_name)
	{
		$dir=$this->get_customer_directory($customer_id);	
		return $dir."/".$file_name;
	}

	public function add_and_move_customer_task_exec_file($props)
	{
		$dir=$this->get_customer_directory($props['customer_id']);
		$new_filename="task-exec-".$props['task_id']."-".$props['exec_count'].".".$props['file_extension'];
		$new_path=$dir."/".$new_filename;

		@move_uploaded_file($props['temp_path'], $new_path);

		return $new_filename;
	}

	public function add_customer_log($customer_id,$log_type,$desc)
	{
		if(isset($this->customer_log_types[$log_type]))
			$type_index=$this->customer_log_types[$log_type];
		else
			$type_index=0;

		$CI=&get_instance();
		if(isset($CI->in_admin_env) && $CI->in_admin_env)
		{
			$desc["active_user_id"]=$CI->user->get_id();
			$desc["active_user_code"]=$CI->user->get_code();
			$desc["active_user_name"]=$CI->user->get_name();
		}

		$this->load->model("time_manager_model");
		$desc['academic_year']=$this->time_manager_model->get_current_academic_time_name();

		$desc['visitor_ip']=$this->input->ip_address();	
		$desc['visitor_id']=$this->log_manager_model->get_visitor_id();
		$ua=$this->input->user_agent();
		if($ua)
      	$desc["visitor_user_agent"]=$ua;
		
		$log_path=$this->get_customer_log_path($customer_id,$type_index);

		$string='{"log_type":"'.$log_type.'"';
		$string.=',"log_type_index":"'.$type_index.'"';

		foreach($desc as $index=>$val)
		{
			$index=trim($index);
			$index=preg_replace('/[\\\'\"]+/', "", $index);
			$index=preg_replace('/\s+/', "_", $index);

			$val=trim($val);
			$val=preg_replace('/[\\\'\"]+/', "", $val);
			$val=preg_replace('/\s+/', " ", $val);
			
			$string.=',"'.$index.'":"'.$val.'"';
		}
		$string.="}";

		file_put_contents($log_path, $string);
		
		return;
	}

	//it returns an array with two index, 'results' which specifies  logs
	//and total which indicates the total number of logs 
	public function get_customer_logs($customer_id,$filter=array())
	{
		$dir=$this->get_customer_directory($customer_id);
		$file_names=scandir($dir, SCANDIR_SORT_DESCENDING);

		$logs=array();
		$count=-1;
		$start=0;
		if(isset($filter['start']))
			$start=(int)$filter['start'];
		$length=sizeof($file_names);
		if(isset($filter['length']))
			$length=(int)$filter['length'];
		
		$selected_log_type_codes=array();
		if(isset($filter['log_types']))
			foreach($filter['log_types'] as $type)
				$selected_log_type_codes[]=$this->customer_log_types[$type];


		foreach($file_names as $fn)
		{
			if(!preg_match("/^log-/", $fn))
				continue;

			$tmp=explode(".", $fn);
			list($date_time,$log_type)=explode("#",$tmp[0]);
			list($date,$time)=explode(",",$date_time);
			$time=str_replace("-", ":", $time);
			$date=str_replace(array("log-","-"), array("","/"), $date);
			$date_time=$date." ".$time;

			//now we have timestamp and log_type of this log
			//and we can filter logs we don't want here;
			if($selected_log_type_codes)
				if(!in_array($log_type ,$selected_log_type_codes))
					continue;

			$count++;
			if($count < $start)
				continue;
			if($count >= ($start+$length))
				continue;

			//reading log
			$log=json_decode(file_get_contents($dir."/".$fn));
			if($log)
				$log->timestamp=$date_time;
			$logs[]=$log;
		}

		$total=$count+1;

		return  array(
			"results"	=> $logs
			,"total"		=> $total
		);
	}

	private function get_customer_log_path($customer_id,$type_index)
	{
		$customer_dir=$this->get_customer_directory($customer_id);
		
		$dtf=DATE_FUNCTION;

		$s=0;
		do	
		{
			$dt=$dtf("Y-m-d,H-i-s",time()+$s);	
			
			$ext=$this->customer_log_file_extension;
			$tp=sprintf("%02d",$type_index);

			$log_path=$customer_dir."/log-".$dt."#".$tp.".".$ext;
			$s+=1;
		}while(file_exists($log_path));
		
		return $log_path;
	}

	private function get_customer_directory($customer_id)
	{
		$dir1=(int)($customer_id/1000);
		$dir2=$customer_id % 1000;
		
		$path1=$this->customer_log_dir."/".$dir1;
		if(!file_exists($path1))
			mkdir($path1,0777);

		$path2=$this->customer_log_dir."/".$dir1."/".$dir2;
		if(!file_exists($path2))
			mkdir($path2,0777);

		return $path2;
	}

	public function get_provinces($lang_id)
	{
		$this->db->select("*");
		$this->db->from("province");
		if($lang_id)
			$this->db->where("province_lang",$lang_id);
		$this->db->order_by("province_name ASC");
		$query=$this->db->get();

		return $query->result_array();
	}

	public function get_cities_with_names($lang_id)
	{
		$this->db->from("city");
		$this->db->join("province","city_province_id = province_id","left");
		if($lang_id)
			$this->db
				->where("city_lang",$lang_id)
				->where("province_lang",$lang_id)
				->order_by("province_id asc, city_name asc");
		else
			$this->db->order_by("province_id asc, city_id asc");
		$query=$this->db->get();	

		$ret=array();
		foreach($query->result_array() as $row)
			$ret[$row['province_name']][]=$row['city_name'];

		return $ret;		
	}

	public function get_cities($lang_id)
	{
		$this->db->from("city");
		$this->db->join("province","city_province_id = province_id","left");
		if($lang_id)
			$this->db
				->where("city_lang",$lang_id)
				->where("province_lang",$lang_id)
				->order_by("province_id asc, city_name asc");
		else
			$this->db->order_by("province_id asc, city_id asc");
		$query=$this->db->get();	

		$ret=array();
		foreach($query->result_array() as $row)
			$ret[$row['province_id']][$row['city_id']]=$row['city_name'];

		return $ret;		
	}

	private function insert_province_and_citiy_tables_to_db()
	{
		$result=$this->db->query("show tables like '%city' ");
		if(sizeof($result->result_array()))
			return;

		$this->load->helper("province_city_installer_helper");

		insert_Iran_provinces_and_cities_to_db();

		return;
	}

	public function login($code,$pass)
	{
		$ret=FALSE;

		$result=$this->db->get_where(
			$this->customer_table_name,
			array(
				"LOWER(customer_code)"=>strtolower($code)
				,"customer_active"=>1
			)
		);

		if($result->num_rows() == 1)
		{
			$row=$result->row_array();		
			
			if($row['customer_pass'] === $this->getPass($pass, $row['customer_salt']))
			{
				$this->set_customer_logged_in($row['customer_id'],$code);
				$customer_id=$row['customer_id'];
				
				$ret=TRUE;
			}
		}

		$props=array(
			"claimed_code"=>$code
			,"result"=>(int)$ret
		);

		if(isset($customer_id))
		{
			$this->add_customer_log($customer_id,'CUSTOMER_LOGIN',$props);
			$props['customer_id']=$customer_id;
		}
		
		$this->log_manager_model->info("CUSTOMER_LOGIN",$props);

		return $ret;
	}

	public function user_login_as_customer($customer_id)
	{
		//You may disable this feature by uncommenting the following line
		//specially when each customer has financial records and credits on your system
		//It is also possible to create a sudo-module for this action and limit users
		//who may access customers accounts directly

		//return FALSE;
		$this->load->model("constant_manager_model");
		if(!$this->constant_manager_model->get("allow_user_login_to_customer_account"))
			return FALSE;

		$ret=FALSE;
		
		$result=$this->db->get_where(
			$this->customer_table_name,
			array(
				"customer_id"			=> $customer_id
				,"customer_active"	=> 1
			)
		);
		
		if($result->num_rows() == 1)
		{
			$row=$result->row_array();
			$customer_code=$row['customer_code'];

			if($customer_code)
			{
				$this->set_customer_logged_in($customer_id,$customer_code);
				$ret=TRUE;
			}
		}

		$props=array(
			"customer_id"=>$customer_id
			,"result"=>(int)$ret
			,"type"=>"user_logged_in_as_customer"
		);

		if(isset($customer_code))
			$props['customer_code']=$customer_id;
		
		$this->add_customer_log($customer_id,'CUSTOMER_LOGIN',$props);

		$this->log_manager_model->info("CUSTOMER_LOGIN",$props);

		return $ret;
	}

	public function login_openid($email,$openid_server)
	{
		return FALSE;

		$ret=FALSE;
		
		$result=$this->db->get_where($this->customer_table_name,array("customer_email"=>$email));
		
		if($result->num_rows() == 1)
		{
			$row=$result->row_array();
			$customer_id=$row['customer_id'];
			$this->set_customer_logged_in($customer_id,$email);

			$ret=TRUE;
		}

		$props=array(
			"claimed_email"=>$email
			,"openid_server"=>$openid_server
			,"result"=>(int)$ret
		);

		if(isset($customer_id))
		{
			$this->add_customer_log($customer_id,'CUSTOMER_LOGIN',$props);
			$props['customer_id']=$customer_id;
		}

		$this->log_manager_model->info("CUSTOMER_LOGIN",$props);

		return $ret;
	}

	public function get_logged_customer_info()
	{
		if(!$this->has_customer_logged_in())
			return NULL;

		$customer_id=$this->session->userdata(SESSION_VARS_PREFIX."customer_id");
		return $this->get_customer_info($customer_id);
	}

	public function get_logged_customer_id()
	{
		if(!$this->has_customer_logged_in())
			return NULL;

		return $this->session->userdata(SESSION_VARS_PREFIX."customer_id");
	}

	public function get_logged_customer_code()
	{
		if(!$this->has_customer_logged_in())
			return NULL;

		return $this->session->userdata(SESSION_VARS_PREFIX."customer_code");
	}

	//returns a new pass or FALSE
	public function set_new_password($email)
	{
		return FALSE;

		$ret=FALSE;

		//$pass=random_string("numeric",7);
		$pass=random_string("alnum",7);
		$salt=random_string("alnum",32);

		$this->db->set("customer_pass", $this->getPass($pass,$salt));
		$this->db->set("customer_salt", $salt);
		$this->db->where("customer_email",$email);
		$this->db->limit(1);
		$this->db->update($this->customer_table_name);

		$props=array("customer_email"=>$email);

		if($this->db->affected_rows())
		{
			$ret=TRUE;
			$result=$this->db->get_where($this->customer_table_name,array("customer_email"=>$email));
			$row=$result->row_array();
			$customer_id=$row['customer_id'];

			$this->add_customer_log($customer_id,'CUSTOMER_PASS_CHANGE',$props);
			$props['customer_id']=$customer_id;
		}

		$props['result']=$ret;
		
		$this->log_manager_model->info("CUSTOMER_PASS_CHANGE",$props);

		if($ret)
			return $pass;
		else
			return FALSE;
	}

	//returns a new pass or FALSE
	public function set_new_password_by_id($customer_id, $pass=NULL)
	{
		$ret=FALSE;

		//$pass=random_string("numeric",7);
		if(!$pass)
			$pass=random_string("alnum",7);
		$salt=random_string("alnum",32);

		$this->db->set("customer_pass", $this->getPass($pass,$salt));
		$this->db->set("customer_salt", $salt);
		$this->db->where("customer_id",$customer_id);
		$this->db->limit(1);
		$this->db->update($this->customer_table_name);

		$props=array("customer_id"=>$customer_id);

		if($this->db->affected_rows())
		{
			$ret=TRUE;
			$this->add_customer_log($customer_id,'CUSTOMER_PASS_CHANGE',$props);
		}

		$props['result']=$ret;
		
		$this->log_manager_model->info("CUSTOMER_PASS_CHANGE",$props);

		if($ret)
			return $pass;
		else
			return FALSE;
	}

	private function set_customer_logged_in($customer_id,$customer_code)
	{
		$this->session->set_userdata(SESSION_VARS_PREFIX."customer_logged_in","true");
		$this->session->set_userdata(SESSION_VARS_PREFIX."customer_id",$customer_id);
		$this->session->set_userdata(SESSION_VARS_PREFIX."customer_code",$customer_code);
		$this->session->set_userdata(SESSION_VARS_PREFIX."customer_last_visit",time());

		return;
	}

	public function set_customer_logged_out()
	{
		$customer_id=$this->session->userdata(SESSION_VARS_PREFIX."customer_id");
		$customer_code=$this->session->userdata(SESSION_VARS_PREFIX."customer_code");

		$this->session->unset_userdata(SESSION_VARS_PREFIX."customer_logged_in");
		$this->session->unset_userdata(SESSION_VARS_PREFIX."customer_id");
		$this->session->unset_userdata(SESSION_VARS_PREFIX."customer_code");
		$this->session->unset_userdata(SESSION_VARS_PREFIX."customer_last_visit");

		if($customer_id)
		{
			$props=array(
				'customer_id'=>$customer_id
				,'customer_code'=>$customer_code
			);

			$this->add_customer_log($customer_id,'CUSTOMER_LOGOUT',$props);	
			$this->log_manager_model->info("CUSTOMER_LOGOUT",$props);
		}

		return;
	}

	public function has_customer_logged_in()
	{
		if($this->session->userdata(SESSION_VARS_PREFIX."customer_logged_in") !== 'true')
			return FALSE;

		if(time()-$this->session->userdata(SESSION_VARS_PREFIX."customer_last_visit") < CUSTOMER_SESSION_EXPIRATION)
		{
			$this->session->set_userdata(SESSION_VARS_PREFIX."customer_last_visit",time());
			return TRUE;
		}
			
		$this->set_customer_logged_out();

		return FALSE;
	}

	private function getPass($pass,$salt)
	{
		return md5(md5($pass).$salt);
	}

	public function send_registeration_mail($email,$pass)
	{
		//In EMS, we don't send registeration email
		return ;

		$this->lang->load('email_lang',$this->selected_lang);		
		$this->lang->load('ae_customer_details_lang',$this->selected_lang);		

		$subject=$this->lang->line("registeration_email_subject");
		$subject=$subject.$this->lang->line("header_separator").$this->lang->line("main_name");
		$content=str_replace(
			array('$email','$pass','$link')
			,array($email,$pass,get_link("customer_login")),
			$this->lang->line("registeration_email_content")
		);
		
		$message=str_replace(
			array('$content','$slogan','$response_to'),
			array($content,$this->lang->line("slogan"),"")
			,$this->lang->line("email_template")
		);

		burge_cmf_send_mail($email,$subject,$message);

		return;
	}
}