<?php
Mage::app('default');
include 'Popup.php';
function getMazeTable($tbl){
	$tableName = Mage::getSingleton('core/resource')->getTableName($tbl);
	return($tableName);
}
//customer will be re-directed to this file. this file handle all token, email etc things.
class Loginradius_Sociallogin_IndexController extends Mage_Core_Controller_Front_Action
{
	var $blockObj;
	private $loginRadiusPopMsg;
	private $loginRadiusPopErr;
	protected function _getSession(){
		return Mage::getSingleton('sociallogin/session');
	}
	// if token is posted then this function will be called. It will login user if already in database. else if email is provided by api, it will insert data and login user. It will handle all after token.
	function tokenHandle() {
		$ApiSecrete = $this->blockObj->getApiSecret();
		$user_obj = $this->blockObj->getProfileResult($ApiSecrete);
		$id = $user_obj->ID;
		if(empty($id)){
			//invalid user
			return;
		}
		// social linking variable
		$socialLinking = false;
		// social linking
		if(isset($_GET['loginradiuslinking']) && trim($_GET['loginradiuslinking']) == 1){
			$socialLinking = true;
		}
		//valid user, checking if user in database?
		$socialLoginIdResult = $this->loginRadiusRead( "sociallogin", "get user", array($id), true );
		$socialLoginIds = $socialLoginIdResult->fetchAll();
		foreach( $socialLoginIds as $socialLoginId ){
			$select = $this->loginRadiusRead( "customer_entity", "get user2", array($socialLoginId['entity_id']), true );
			if($rowArray = $select->fetch()){
				if( $socialLoginId['verified'] == "0" ){
					if(!$socialLinking){
						SL_popUpWindow( "Please verify your email to login.", "", false );
						return;
					}else{
						// link account
						$this->loginRadiusSocialLinking(Mage::getSingleton("customer/session")->getCustomer()->getId(), $user_obj->ID, $user_obj->Provider, $user_obj->ThumbnailImageUrl);
						header("Location:".Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_LINK)."customer/account/?LoginRadiusLinked=1");
						die;
					}
				}
				break;
			}
		}
		$sociallogin_id = $rowArray['entity_id'];
		if(!empty($sociallogin_id)){	//user is in database
			if(!$socialLinking){
				$this->socialLoginUserLogin( $sociallogin_id, $id );
				return;
			}else{
				// account already exists
				header("Location:".Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_LINK)."customer/account/?LoginRadiusLinked=0");
				die;
			}
		}
		// social linking
		if($socialLinking){
			$this->loginRadiusSocialLinking(Mage::getSingleton("customer/session")->getCustomer()->getId(), $user_obj->ID, $user_obj->Provider, $user_obj->ThumbnailImageUrl, true);
			header("Location:".Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_LINK)."customer/account/?LoginRadiusLinked=1");
			die;
		}
		if( !empty($user_obj->Email[0]->Value) ){
			//if email is provided by provider then check if it's in table
			$email = $user_obj->Email['0']->Value;
			$select = $this->loginRadiusRead( "customer_entity", "email exists login", array($email), true );
			if( $rowArray = $select->fetch() ) {
				$sociallogin_id = $rowArray['entity_id'];
				if(!empty($sociallogin_id)) {
					//user is in customer table
					if( $this->blockObj->getLinking() == "1" ){    // Social Linking
						$this->loginRadiusSocialLinking($sociallogin_id, $user_obj->ID, $user_obj->Provider, $user_obj->ThumbnailImageUrl);
					}
					$this->socialLoginUserLogin( $sociallogin_id, $user_obj->ID );
					return;
				}
			}
			
			$socialloginProfileData = $this->socialLoginFilterData( $email, $user_obj );
			$socialloginProfileData['lrId'] = $user_obj->ID;
			if($this->blockObj->getProfileFieldsRequired() == 1){
				$id = $user_obj->ID;
				$this->setInSession($id, $socialloginProfileData);
				// show a popup to fill required profile fields 
				SL_popUpWindow("Please provide following details:-", "", true, $socialloginProfileData, false);
				return;
			}
			$this->socialLoginAddNewUser( $socialloginProfileData );
			return;
		}

		// empty email
		if( $this->blockObj->getEmailRequired() == 0 ) { 	// dummy email
			$email = $this->loginradius_get_randomEmail( $user_obj );
			$socialloginProfileData = $this->socialLoginFilterData( $email, $user_obj );
			$socialloginProfileData['lrId'] = $user_obj->ID;
			if($this->blockObj->getProfileFieldsRequired() == 1){
				$id = $user_obj->ID;
				$socialloginProfileData = $this->socialLoginFilterData( $email, $user_obj );
				$this->setInSession($id, $socialloginProfileData);
				// show a popup to fill required profile fields
				SL_popUpWindow("Please provide following details:-", "", true, $socialloginProfileData, false);
				return;
			}
			// create new user
			$this->socialLoginAddNewUser( $socialloginProfileData );
			return;
		}else {		// show popup
			$id = $user_obj->ID;
			$socialloginProfileData = $this->socialLoginFilterData( $email, $user_obj );
			$this->setInSession($id, $socialloginProfileData);
			if($this->blockObj->getProfileFieldsRequired() == 1){
				// show a popup to fill required profile fields 
				SL_popUpWindow("Please provide following details:-", "", true, $socialloginProfileData, true);
			}else{
				SL_popUpWindow($this->loginRadiusPopMsg, "", true, array(), true, true);
			}
			return;
		}
	}
	
    function loginradius_get_randomEmail( $user_obj ) {
      switch ( $user_obj->Provider ) {
        case 'twitter':
          $email = $user_obj->ID. '@' . $user_obj->Provider . '.com';
          break;
        case 'linkedin':
          $email = $user_obj->ID. '@' . $user_obj->Provider . '.com';
          break;
        default:
          $Email_id = substr( $user_obj->ID, 7 );
          $Email_id2 = str_replace("/", "_", $Email_id);
          $email = str_replace(".", "_", $Email_id2) . '@' . $user_obj->Provider . '.com';
          break;
      }
	  return $email;
    }
	// social linking
	function loginRadiusSocialLinking($entityId, $socialId, $provider, $thumbnail, $unique = false){
		// check if any account from this provider is already linked
		if($unique && $this->loginRadiusRead( "sociallogin", "provider exists in sociallogin", array($entityId, $provider))){
			header("Location:".Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_LINK)."customer/account/?LoginRadiusLinked=2");
			die;
		}
		$socialLoginLinkData = array();
		$socialLoginLinkData['sociallogin_id'] = $socialId;
		$socialLoginLinkData['entity_id'] = $entityId;
		$socialLoginLinkData['provider'] = empty($provider) ? "" : $provider;
		$socialLoginLinkData['avatar'] = $this->socialLoginFilterAvatar( $socialId, $thumbnail, $provider );
		$socialLoginLinkData['avatar'] = ($socialLoginLinkData['avatar'] == "") ? NULL : $socialLoginLinkData['avatar'] ;
		$this->SocialLoginInsert( "sociallogin", $socialLoginLinkData );
	}
	function socialLoginFilterData( $email, $user_obj ) {
		$socialloginProfileData = array();
		$socialloginProfileData['Email'] = $email;
		$socialloginProfileData['Provider'] = empty($user_obj->Provider) ? "" : $user_obj->Provider;
		$socialloginProfileData['FirstName'] = empty($user_obj->FirstName) ? "" : $user_obj->FirstName;
		$socialloginProfileData['FullName'] = empty($user_obj->FullName) ? "" : $user_obj->FullName;
		$socialloginProfileData['NickName'] = empty($user_obj->NickName) ? "" : $user_obj->NickName;
		$socialloginProfileData['LastName'] = empty($user_obj->LastName) ? "" : $user_obj->LastName;
		if(is_array($user_obj->Addresses)){
			foreach($user_obj->Addresses as $address){
				if(isset($address->Address1) && !empty($address->Address1)){
					$socialloginProfileData['Address'] = $address->Address1;
					break;
				}
			}
		}elseif(is_string($user_obj->Addresses)){
			$socialloginProfileData['Address'] = $user_obj->Addresses != "" ? $user_obj->Addresses : "";
		}else{
			$socialloginProfileData['Address'] = "";
		}
		$socialloginProfileData['PhoneNumber'] = empty( $user_obj->PhoneNumbers['0']->PhoneNumber ) ? "" : $user_obj->PhoneNumbers['0']->PhoneNumber;
		$socialloginProfileData['State'] = empty($user_obj->State) ? "" : $user_obj->State;
		$socialloginProfileData['City'] = empty($user_obj->City) || $user_obj->City == "unknown" ? "" : $user_obj->City;
		$socialloginProfileData['Industry'] = empty($user_obj->Positions['0']->Comapny->Name) ? "" : $user_obj->Positions['0']->Comapny->Name;
		$socialloginProfileData['Country'] = empty($user_obj->Country) || $user_obj->Country == "unknown" ? "" : $user_obj->Country;
		$socialloginProfileData['thumbnail'] = $this->socialLoginFilterAvatar( $user_obj->ID, $user_obj->ThumbnailImageUrl, $socialloginProfileData['Provider'] );
		$explode= explode("@",$email);
		if( empty( $socialloginProfileData['FirstName'] ) && !empty( $socialloginProfileData['FullName'] ) ){
			$socialloginProfileData['FirstName'] = $socialloginProfileData['FullName'];
		}elseif(empty($socialloginProfileData['FirstName'] ) && !empty( $socialloginProfileData['NickName'] )){
			$socialloginProfileData['FirstName'] = $socialloginProfileData['NickName'];
		}elseif(empty($socialloginProfileData['FirstName'] ) && empty($socialloginProfileData['NickName'] ) && !empty($socialloginProfileData['FullName'] ) ){
			$socialloginProfileData['FirstName'] = $explode[0];
		}
		if($socialloginProfileData['FirstName'] == '' ){
			$letters = range('a', 'z');
			for($i=0;$i<5;$i++){
				$socialloginProfileData['FirstName'] .= $letters[rand(0,26)];
			}
		}
		$socialloginProfileData['Gender'] = (!empty($user_obj->Gender) ? $user_obj->Gender : '');
		if( strtolower(substr($socialloginProfileData['Gender'], 0, 1)) == 'm' ){
			$socialloginProfileData['Gender'] = '1';
		}elseif( strtolower(substr($socialloginProfileData['Gender'], 0, 1)) == 'f' ){
			$socialloginProfileData['Gender'] = '2';
		}else{
			$socialloginProfileData['Gender'] = '';
		}
		$socialloginProfileData['BirthDate'] = (!empty($user_obj->BirthDate) ? $user_obj->BirthDate : '');
		if( $socialloginProfileData['BirthDate'] != "" ){
			switch( $socialloginProfileData['Provider'] ){
				case 'facebook':
				case 'foursquare':
				case 'yahoo':
				case 'openid':
				break;
				
				case 'google':
				$temp = explode( '/', $socialloginProfileData['BirthDate'] );  // yy/mm/dd
				$socialloginProfileData['BirthDate'] = $temp[1]."/".$temp[2]."/".$temp[0];
				break;
				
				case 'twitter':
				case 'linkedin':
				case 'vkontakte':
				case 'live';
				$temp = explode( '/', $socialloginProfileData['BirthDate'] );   // dd/mm/yy
				$socialloginProfileData['BirthDate'] = $temp[1]."/".$temp[0]."/".$temp[2];
				break;
			}
		}
		return $socialloginProfileData;
	}

	function socialLoginFilterAvatar( $id, $ImgUrl, $provider ){
		$thumbnail = (!empty($ImgUrl) ? trim($ImgUrl) : '');
		if (empty($thumbnail) && ( $provider == 'facebook' ) ) {
		  $thumbnail = "http://graph.facebook.com/" . $id . "/picture?type=large";
		}
		return $thumbnail;
	}

	function socialLoginUserLogin( $entityId, $socialId ) {
		$session = Mage::getSingleton("customer/session");
		$session->loginById($entityId);
		$session->setLoginRadiusId($socialId);
		$write_url = $this->blockObj->getCallBack();
		$Hover = $this->blockObj->getRedirectOption();
		$url = Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_LINK);
		// check if logged in from callback page
		if(isset($_GET['loginradiuscheckout'])){
			header( 'Location: '.Mage::helper('checkout/url')->getCheckoutUrl() );
			exit();
			return;
		}
		if($Hover == 'account'){
			header( 'Location: '.$url.'customer/account' );
			exit();
			return;
		}elseif($Hover == 'index' ){
			header( 'Location: '.$url.'') ;
			exit();
			return;
		}elseif( $Hover == 'custom' && $write_url != '' ) {
			header( 'Location: '.$write_url.'' );
			exit();
			return;
		}else{
			 $currentUrl = Mage::helper('core/url')->getCurrentUrl();
			 header( 'Location: '.$currentUrl);
			 exit();
			 return;
		}
	}

	function setInSession( $id, $socialloginProfileData ){
		$socialloginProfileData['lrId'] = $id;
		Mage::getSingleton('core/session')->setSocialLoginData( $socialloginProfileData );
	}

	function loginRadiusEmail( $subject, $message, $to, $toName ){
		$storeName =  Mage::app()->getStore()->getGroup()->getName();
		$mail = new Zend_Mail(); //class for mail
		$mail->setBodyHtml( $message ); //for sending message containing html code
		$mail->setFrom( "Owner", $storeName );
		$mail->addTo( $to, $toName );
		//$mail->addCc($cc, $ccname);    //can set cc
		//$mail->addBCc($bcc, $bccname);    //can set bcc
		$mail->setSubject( $subject );
		try{
		  $mail->send();
		}catch(Exception $ex) {
		}
	}

	function socialLoginAddNewUser( $socialloginProfileData, $verify = false ) {
		// add new user magento way
		$websiteId = Mage::app()->getWebsite()->getId();
		$store = Mage::app()->getStore();
		$customer = Mage::getModel("customer/customer");
		$customer->website_id = $websiteId; 
		$customer->setStore($store);
		$customer->firstname = $socialloginProfileData['FirstName'];
		$customer->lastname = $socialloginProfileData['LastName'] == "" ? $socialloginProfileData['FirstName'] : $socialloginProfileData['LastName'];
		$customer->email = $socialloginProfileData['Email'];
		$customer->dob = $socialloginProfileData['BirthDate'];
		$customer->gender = $socialloginProfileData['Gender'];
		$loginRadiusPwd = $customer->generatePassword(10);
		$customer->password_hash = md5( $loginRadiusPwd );
		$customer->setConfirmation(null);
		$customer->save();

		//$address = new Mage_Customer_Model_Address();
		$address = Mage::getModel("customer/address");
		$address->setCustomerId($customer->getId());
		$address->firstname = $customer->firstname;
		$address->lastname = $customer->lastname;
		$address->country_id = ucfirst( $socialloginProfileData['Country'] ); //Country code here
		if(isset($socialloginProfileData['Zipcode'])){
			$address->postcode = $socialloginProfileData['Zipcode'];
		}
		$address->city = ucfirst( $socialloginProfileData['City'] );
		// If country is USA, set up province
		if(isset($socialloginProfileData['Province'])){
			$address->region = $socialloginProfileData['Province'];
		}
		$address->telephone = $socialloginProfileData['PhoneNumber'];
		$address->company = ucfirst( $socialloginProfileData['Industry'] );
		$address->street = ucfirst( $socialloginProfileData['Address'] );
		$address->save();
		// add info in sociallogin table
		if( !$verify ){
			$fields = array();
			$fields['sociallogin_id'] = $socialloginProfileData['lrId'] ;
			$fields['entity_id'] = $customer->getId();
			$fields['avatar'] = $socialloginProfileData['thumbnail'] ;
			$fields['provider'] = $socialloginProfileData['Provider'] ;
			$this->SocialLoginInsert( "sociallogin", $fields );
			$loginRadiusUsername = $socialloginProfileData['FirstName']." ".$socialloginProfileData['LastName'];
			// email notification to user
			if( $this->blockObj->notifyUser() == "1" ){
				$loginRadiusMessage = $this->blockObj->notifyUserText();
				if( $loginRadiusMessage == "" ){
					$loginRadiusMessage = "Welcome to ".$store->getGroup()->getName().". You can login to the store using following e-mail address and password:-";
				}
				$loginRadiusMessage .= "<br/>".
									   "Email : ".$socialloginProfileData['Email'].
									   "<br/>Password : ".$loginRadiusPwd;
									   
				$this->loginRadiusEmail( "Welcome ".$loginRadiusUsername."!", $loginRadiusMessage, $socialloginProfileData['Email'], $loginRadiusUsername );
			}
			// new user notification to admin
			if( $this->blockObj->notifyAdmin() == "1" ){
				$loginRadiusAdminEmail = Mage::getStoreConfig('trans_email/ident_general/email');
				$loginRadiusAdminName = Mage::getStoreConfig('trans_email/ident_general/name');
				$loginRadiusMessage = trim($this->blockObj->notifyAdminText());
				if( $loginRadiusMessage == "" ){
					$loginRadiusMessage = "New customer has been registered to your store with following details:-";
				}
				$loginRadiusMessage .= "<br/>".
									   "Name : ".$loginRadiusUsername."<br/>".
									   "Email : ".$socialloginProfileData['Email'];
				$this->loginRadiusEmail( "New User Registration", $loginRadiusMessage, $loginRadiusAdminEmail, $loginRadiusAdminName );
			}
			//login and redirect user
			$this->socialLoginUserLogin( $customer->getId(), $fields['sociallogin_id'] );
		}
		if( $verify ){
			$this->verifyUser( $socialloginProfileData['lrId'], $customer->getId(), $socialloginProfileData['thumbnail'], $socialloginProfileData['Provider'], $socialloginProfileData['Email'] );
		}
	}

	private function SocialLoginInsert( $lrTable, $lrInsertData, $update = false, $value ){
		$connection = Mage::getSingleton('core/resource')
							->getConnection('core_write');
		$connection->beginTransaction();
		$sociallogin = getMazeTable($lrTable);
		if( !$update ){
			$connection->insert($sociallogin, $lrInsertData);
		}else{
			$query = "UPDATE {$sociallogin} SET ".$lrInsertData." WHERE ".$value;
			$offset = $connection->query( $query );
		}
		$connection->commit();
	}

	private function SocialLoginShowLayout() {
		$this->loadLayout();     
		$this->renderLayout();
	}

	private function loginRadiusRead( $table, $handle, $params, $result = false ){
		$socialLoginConn = Mage::getSingleton('core/resource')
							->getConnection('core_read');
		$Tbl = getMazeTable($table); 
		$websiteId = Mage::app()->getWebsite()->getId();
		$storeId = Mage::app()->getStore()->getId();
		$query = "";
		switch( $handle ){
			case "email exists pop1":
			$query = "select entity_id from $Tbl where email = '".$params[0]."' and website_id = $websiteId and store_id = $storeId";
			break;
			case "get user":
			$query = "select entity_id, verified from $Tbl where sociallogin_id= '".$params[0]."'";
			break;
			case "get user2":
			$query = "select entity_id from $Tbl where entity_id = ".$params[0]." and website_id = $websiteId and store_id = $storeId";
			break;
			case "email exists login":
			$query = "select * from $Tbl where email = '".$params[0]."' and website_id = $websiteId and store_id = $storeId";
			break;
			case "email exists sl":
			$query = "select verified,sociallogin_id from $Tbl where entity_id = '".$params[0]."' and provider = '".$params[1]."'";
			break;
			case "provider exists in sociallogin":
			$query = "select entity_id from $Tbl where entity_id = '".$params[0]."' and provider = '".$params[1]."'";
			break;
			case "verification":
			$query = "select entity_id, provider from $Tbl where vkey = '".$params[0]."'";
			break;
			case "verification2":
			$query = "select entity_id from $Tbl where entity_id = ".$params[0]." and provider = '".$params[1]."' and vkey != '' ";
			break;
		}
		$select = $socialLoginConn->query($query);
		if( $result ){
			return $select;
		}
		if( $rowArray = $select->fetch() ) { 
			return true;
		}
		return false;
	}
	
	private function verifyUser( $slId, $entityId, $avatar, $provider, $email ){
		$vKey = md5(uniqid(rand(), TRUE));
		$data = array();
		$data['sociallogin_id'] = $slId;
		$data['entity_id'] = $entityId;
		$data['avatar'] = $avatar;
		$data['verified'] = "0";
		$data['vkey'] = $vKey;
		$data['provider'] = $provider;
		// insert details in sociallogin table
		$this->SocialLoginInsert( "sociallogin", $data );
		// send verification mail
		$message = trim($this->blockObj->verificationText());
		if( $message == "" ){
			$message = "Please click on the following link or paste it in browser to verify your email:-";
		}
		$message .= "<br/>".Mage::getBaseUrl()."sociallogin/?loginRadiusKey=".$vKey;
		$this->loginRadiusEmail( "Email verification", $message, $email, $email);
		// show popup message
		SL_popUpWindow( "Confirmation link Has Been Sent To Your Email Address. Please verify your email by clicking on confirmation link.", "", false );
		$this->SocialLoginShowLayout();
		return;
	}
	
   	public function indexAction() {
		$this->blockObj = new Loginradius_Sociallogin_Block_Sociallogin();
		$this->loginRadiusPopMsg = trim($this->blockObj->getPopupText() );
		$this->loginRadiusPopMsg = $this->loginRadiusPopMsg == "" ? "Please enter your email to proceed" : $this->loginRadiusPopMsg;
		$this->loginRadiusPopErr = trim($this->blockObj->getPopupError() );
		$this->loginRadiusPopErr = $this->loginRadiusPopErr == "" ? "Email you entered is either invalid or already registered. Please enter a valid email." : $this->loginRadiusPopErr;
		if(isset($_REQUEST['token'])) {
			$this->tokenHandle();
			$this->loadLayout();     
			$this->renderLayout();
			return;
		}
		
		// email verification
		if( isset($_GET['loginRadiusKey']) && !empty($_GET['loginRadiusKey']) ){
			$loginRadiusVkey = trim( $_GET['loginRadiusKey'] );
			// get entity_id and provider of the vKey
			$result = $this->loginRadiusRead( "sociallogin", "verification", array( $loginRadiusVkey ), true );
			if( $temp = $result->fetch() ){
				// set verified status true at this verification key
				$tempUpdate = "verified = '1', vkey = ''";
				$tempUpdate2 = "vkey = '".$loginRadiusVkey."'";
				$this->SocialLoginInsert( "sociallogin", $tempUpdate, true, $tempUpdate2 );
				SL_popUpWindow( "Your email has been verified. Now you can login to your account.", "", false );
				
				// check if verification for same provider is still pending on this entity_id
				if( $this->loginRadiusRead( "sociallogin", "verification2", array( $temp['entity_id'], $temp['provider'] ) ) ){
					$tempUpdate = "vkey = ''";
					$tempUpdate2 = "entity_id = ".$temp['entity_id']." and provider = '".$temp['provider']."'";
					$this->SocialLoginInsert( "sociallogin", $tempUpdate, true, $tempUpdate2 );
				}
			}
		}

		$socialLoginProfileData = Mage::getSingleton('core/session')->getSocialLoginData();
		$session_user_id = $socialLoginProfileData['lrId'];
		$loginRadiusPopProvider = $socialLoginProfileData['Provider'];
		$loginRadiusAvatar = $socialLoginProfileData['thumbnail'];

		if( isset($_POST['LoginRadiusRedSliderClick']) ) {
			if(!empty($session_user_id) ){
				$loginRadiusProfileData = array();
				// address
				if(isset($_POST['loginRadiusAddress'])){
					$loginRadiusProfileData['Address'] = "";
					$profileAddress = trim($_POST['loginRadiusAddress']);
				}
				// city
				if(isset($_POST['loginRadiusCity'])){
					$loginRadiusProfileData['City'] = "";
					$profileCity = trim($_POST['loginRadiusCity']);
				}
				// country
				if(isset($_POST['loginRadiusCountry'])){
					$loginRadiusProfileData['Country'] = "";
					$profileCountry = trim($_POST['loginRadiusCountry']);
				}
				// phone number
				if(isset($_POST['loginRadiusPhone'])){
					$loginRadiusProfileData['PhoneNumber'] = "";
					$profilePhone = trim($_POST['loginRadiusPhone']);
				}
				// email
				if(isset($_POST['loginRadiusEmail'])){
					$email = trim($_POST['loginRadiusEmail']);
					if( !preg_match("/^[_a-z0-9-]+(\.[_a-z0-9-]+)*@[a-z0-9-]+(\.[a-z0-9-]+)*(\.[a-z]{2,3})$/", $email) ){
						if($this->blockObj->getProfileFieldsRequired() == 1){
							$hideZipCountry = false;
						}else{
							$hideZipCountry = true;
						}
						SL_popUpWindow($this->loginRadiusPopMsg, $this->loginRadiusPopErr, true, $loginRadiusProfileData, true, $hideZipCountry);
						$this->SocialLoginShowLayout();
						return false;
					}
					// check if email already exists
					$userId = $this->loginRadiusRead( "customer_entity", "email exists pop1", array($email), true );
					if( $rowArray = $userId->fetch() ) {  // email exists
						//check if entry exists on same provider in sociallogin table
						$verified = $this->loginRadiusRead( "sociallogin", "email exists sl", array( $rowArray['entity_id'], $loginRadiusPopProvider ), true );
						if( $rowArray2 = $verified->fetch() ){
							// check verified field
							if( $rowArray2['verified'] == "1" ){
								// check sociallogin id
								if( $rowArray2['sociallogin_id'] == $session_user_id ){
									$this->socialLoginUserLogin( $rowArray['entity_id'], $rowArray2['sociallogin_id'] );
									return;
								}else{
									SL_popUpWindow( $this->loginRadiusPopMsg, $this->loginRadiusPopErr, true, array(), true, true );
									$this->SocialLoginShowLayout();
									return;
								}
							}else{
								// check sociallogin id
								if( $rowArray2['sociallogin_id'] == $session_user_id ){
									SL_popUpWindow( "Please verify your email to login", "", false );
									$this->SocialLoginShowLayout();
									return;
								}else{
									// send verification email
									$this->verifyUser( $session_user_id, $rowArray['entity_id'], $loginRadiusAvatar, $loginRadiusPopProvider, $email );
									return;
								}
							}
						}else{
							// send verification email
							$this->verifyUser( $session_user_id, $rowArray['entity_id'], $loginRadiusAvatar, $loginRadiusPopProvider, $email );
							return;
						}
					}
				}
				// validate other profile fields
				if((isset($profileAddress) && $profileAddress == "") || (isset($profileCity) && $profileCity == "") || (isset($profileCountry) && $profileCountry == "") || (isset($profilePhone) && $profilePhone == "")){
					SL_popUpWindow("", "Please fill all the fields", true, $loginRadiusProfileData, true);
					$this->SocialLoginShowLayout();
					return false;
				}
				$socialloginProfileData = Mage::getSingleton('core/session')->getSocialLoginData();
				// assign submitted profile fields to array
				// address
				if(isset($profileAddress)){
					$socialloginProfileData['Address'] = $profileAddress;
				}
				// city
				if(isset($profileCity)){
					$socialloginProfileData['City'] = $profileCity;
				}
				// Country
				if(isset($profileCountry)){
					$socialloginProfileData['Country'] = $profileCountry;
				}
				// Phone Number
				if(isset($profilePhone)){
					$socialloginProfileData['PhoneNumber'] = $profilePhone;
				}
				// Zipcode
				if(isset($_POST['loginRadiusZipcode'])){
					$socialloginProfileData['Zipcode'] = trim($_POST['loginRadiusZipcode']);
				}
				// Province
				if(isset($_POST['loginRadiusProvince'])){
					$socialloginProfileData['Province'] = trim($_POST['loginRadiusProvince']);
				}
				// Email
				if(isset($email)){
					$socialloginProfileData['Email'] = $email;
					$verify = true;
				}else{
					$verify = false;
				}
				Mage::getSingleton('core/session')->unsSocialLoginData(); 	// unset session
				$this->socialLoginAddNewUser( $socialloginProfileData, $verify ); 
			}
		}elseif( isset($_POST['LoginRadiusPopupCancel']) ) { 				// popup cancelled
			Mage::getSingleton('core/session')->unsSocialLoginData(); 		// unset session
			$url = Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_LINK);
			header("Location:".$url);										// redirect to index page
		}
		$this->SocialLoginShowLayout();
    }
	
	/**
	 * Action for AJAX
	 */
	 public function ajaxAction(){
	 	$this->loadLayout();
    	$this->renderLayout();
	 }
}