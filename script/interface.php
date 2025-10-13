<?php
	if (!defined("NOCSRFCHECK")) define('NOCSRFCHECK', 1);
	if (!defined("NOTOKENRENEWAL")) define('NOTOKENRENEWAL', 1);

	require('../config.php');
	dol_include_once('/comm/propal/class/propal.class.php');
	dol_include_once('/commande/class/commande.class.php');
	dol_include_once('/compta/facture/class/facture.class.php');
	// Include product class to retrieve minimum sale price information (EN)
	// Inclure la classe produit pour récupérer les informations de prix de vente minimum (FR)
	dol_include_once('/product/class/product.class.php');

	global $langs;
	if (is_object($langs))
	{
		// Load module translation strings for notices (EN)
		// Charger les traductions du module pour les avertissements (FR)
		$langs->load('arronditotal@arronditotal');
	}

	$newTotal = GETPOST('newTotal');
	$newTotal = price2num($newTotal);

	$fk_object = GETPOST('fk_object', 'int');
	$className = GETPOST('element', 'alpha');
	$className = ucfirst($className);

	if (!class_exists($className)) exit("class $className not found");

	$object = new $className($db);
	$object->fetch($fk_object);

	_exitOrNot($object, $className);

	if (getDolGlobalString('ARRONDITOTAL_B2B')) $field_total = 'total_ht';
	else $field_total = 'total_ttc';

	$coef = 1;
	if (!getDolGlobalString('ARRONDITOTAL_QTY_NEEDED_TO_UPDATE'))
	{
		//var_dump($object);
		if (!empty($object->{$field_total}) && doubleval($object->{$field_total}) != 0) {
			$coef = $newTotal / $object->{$field_total};
		}
	}
	else
	{
		$delta = $object->{$field_total} - $newTotal;
		$totalByQty = _getTotalByQty($object, getDolGlobalString('ARRONDITOTAL_QTY_NEEDED_TO_UPDATE') , $field_total);
		if (!empty($totalByQty) && doubleval($totalByQty) != 0 ) {
			$coef = ($totalByQty - $delta) / $totalByQty;
		}
	}

	$lastLine = false;
	$ignoredProducts = array();
	// Load ignored product/service identifiers for rounding (EN)
	// Charger les identifiants de produits/services à ignorer pour l'arrondi (FR)
	$ignoredRaw = explode(',', (string) getDolGlobalString('ARRONDITOTAL_PRODUITS_IGNORES'));
	foreach ($ignoredRaw as $ignoredId)
	{
	$ignoredId = (int) trim($ignoredId);
	if ($ignoredId > 0)
	{
	$ignoredProducts[$ignoredId] = true;
	}
	}
	foreach ($object->lines as $line)
	{
	if (!empty($ignoredProducts) && !empty($line->fk_product) && !empty($ignoredProducts[(int) $line->fk_product]))
	{
	// Skip rounding on explicitly ignored products/services (EN)
	// Ignorer l'arrondi sur les produits/services explicitement exclus (FR)
	continue;
	}
	if (getDolGlobalString('ARRONDITOTAL_B2B'))
	{
			$tx_tva = 1;
			$pu = $line->subprice;
		}
	else
	{
			$tx_tva = 1 + ($line->tva_tx / 100);
			$pu = $line->subprice * $tx_tva; // calcul du ttc unitaire
		}

		$pu = $pu * $coef; // on applique le coef de réduction
		$pu = $pu / $tx_tva; // calcul du nouvel ht unitaire

		if (getDolGlobalString('ARRONDITOTAL_QTY_NEEDED_TO_UPDATE'))
		{
			if ($line->qty == getDolGlobalString('ARRONDITOTAL_QTY_NEEDED_TO_UPDATE'))
			{

			    if(empty($line->special_code)) {
			        _updateElementLine($object, $line, $pu);
				    $lastLine = $line;
			    }

			}
		}
		else
		{
		    if(empty($line->special_code))  {
		        _updateElementLine($object, $line, $pu);
			    $lastLine = $line;
		    }
		}

	}

	if ($lastLine)
	{
		// on ajoute à la dernière ligne la différence de centime
		$lastLine->fetch($lastLine->id);

		if (getDolGlobalString('ARRONDITOTAL_B2B')) $tx_tva = 1;
		else $tx_tva = 1 + ($lastLine->tva_tx / 100);

		$diff_compta = $newTotal - $object->{$field_total}; // diff entre le total voulu et le nouveau total calculé (décalage de centimes)
		$diff_compta = $diff_compta / $lastLine->qty; // diff à diviser par la qty car on doit obtenir au final un prix unitaire
		$pu = $lastLine->subprice * $tx_tva; // calcul du ttc unitaire
		$pu = $pu + $diff_compta;
		$pu = $pu / $tx_tva; // calcul du nouvel ht unitaire

		_updateElementLine($object, $lastLine, $pu);

		$outputlangs = &_getOutPutLangs($object);
		$object->generateDocument('', $outputlangs);
	}
	else
	{
		setEventMessages($langs->trans('arronditotalErrorNoLine'), null, 'errors');
	}

	function _exitOrNot(&$object, $className)
	{
		if ($object->statut != $className::STATUS_DRAFT)
		{
			setEventMessages($langs->trans('arronditotalErrorObjectNotDraft'), null, 'errors');
			exit;
		}

		if ($object->element == 'facture')
		{
			if ($object->type == Facture::TYPE_REPLACEMENT || $object->type == Facture::TYPE_CREDIT_NOTE || $object->type == Facture::TYPE_SITUATION)
			{
				setEventMessages($langs->trans('arronditotalErrorTypeInvoice'), null, 'errors');
				exit;
			}
		}

	}

	
	function _applyMinimumSalePriceGuard(&$line, $pu)
	{
		global $user, $langs, $db;

		// Determine if the user can ignore the minimum sale price restriction (EN)
		// Déterminer si l'utilisateur peut ignorer la restriction du prix de vente minimum (FR)
		$userCanIgnoreMinPrice = (is_object($user) && !empty($user->rights->produit->ignore_price_min));

		if ($userCanIgnoreMinPrice)
		{
			return $pu;
		}

		// Skip guard when the line is not linked to a product/service (EN)
		// Ignorer la protection lorsque la ligne n'est pas liée à un produit/service (FR)
		if (empty($line->fk_product))
		{
			return $pu;
		}

		// Cache product data and avoid duplicate warnings for performance and clarity (EN)
		// Mettre en cache les données produit et éviter les avertissements en doublon pour les performances et la clarté (FR)
		static $minPriceCache = array();
		static $warnedProducts = array();
		$productId = (int) $line->fk_product;

		// Fetch product data once to access the minimum sale price (EN)
		// Récupérer les données du produit une seule fois pour accéder au prix de vente minimum (FR)
		if (!array_key_exists($productId, $minPriceCache))
		{
			$product = new Product($db);
			if ($product->fetch($productId) > 0)
			{
				$minPriceCache[$productId] = price2num($product->price_min, 'MU');
				$minPriceCache[$productId . '_ref'] = $product->ref;
			}
			else
			{
				$minPriceCache[$productId] = null;
				$minPriceCache[$productId . '_ref'] = '';
			}
		}

		$priceMin = $minPriceCache[$productId];

		// No minimum price configured, so original price can be used (EN)
		// Aucun prix minimum configuré, on conserve donc le prix initial (FR)
		if (empty($priceMin))
		{
			return $pu;
		}

		// Normalize computed price to numeric value for reliable comparison (EN)
		// Normaliser le prix calculé en valeur numérique pour une comparaison fiable (FR)
		$computedPu = price2num($pu, 'MU');

		if ($computedPu < $priceMin)
		{
			if (empty($warnedProducts[$productId]))
			{
				// Ensure rounded price respects minimum sale price when permission is missing (EN)
				// Garantir que le prix arrondi respecte le prix de vente minimum si la permission manque (FR)
				setEventMessages($langs->trans('arronditotalMinPriceGuard', $minPriceCache[$productId . '_ref']), null, 'warnings');
				$warnedProducts[$productId] = true;
			}

			return $priceMin;
		}

		return $pu;
	}

	function _updateElementLine(&$object, &$line, $pu)
	{
		// Apply minimum sale price guard before updating the line (EN)
		// Appliquer la protection du prix de vente minimum avant la mise à jour de la ligne (FR)
		$pu = _applyMinimumSalePriceGuard($line, $pu);

		switch ($object->element)
		{
			case 'propal':
				//$rowid, $pu, $qty, $remise_percent, $txtva, $txlocaltax1=0.0, $txlocaltax2=0.0, $desc='', $price_base_type='HT', $info_bits=0, $special_code=0, $fk_parent_line=0, $skip_update_total=0, $fk_fournprice=0, $pa_ht=0, $label='', $type=0, $date_start='', $date_end='', $array_options=0, $fk_unit=null
				$object->updateline($line->id, $pu, $line->qty, $line->remise_percent, $line->tva_tx, $line->localtax1_tx, $line->localtax2_tx, $line->desc, 'HT', $line->info_bits, $line->special_code, $line->fk_parent_line, $line->skip_update_total, 0, $line->pa_ht, $line->label, $line->product_type, $line->date_start, $line->date_end, $line->array_options, $line->fk_unit);
				break;
			case 'commande':
				//$rowid, $desc, $pu, $qty, $remise_percent, $txtva, $txlocaltax1=0.0,$txlocaltax2=0.0, $price_base_type='HT', $info_bits=0, $date_start='', $date_end='', $type=0, $fk_parent_line=0, $skip_update_total=0, $fk_fournprice=null, $pa_ht=0, $label='', $special_code=0, $array_options=0, $fk_unit=null
				$object->updateline($line->id, $line->desc, $pu, $line->qty, $line->remise_percent, $line->tva_tx, $line->localtax1_tx, $line->localtax2_tx, 'HT', $line->info_bits, $line->date_start, $line->date_end, $line->product_type, $line->fk_parent_line, $line->skip_update_total, 0, $line->pa_ht, $line->label, $line->special_code, $line->array_options, $line->fk_unit);
				break;
			case 'facture':
				//$rowid, $desc, $pu, $qty, $remise_percent, $date_start, $date_end, $txtva, $txlocaltax1=0, $txlocaltax2=0, $price_base_type='HT', $info_bits=0, $type= self::TYPE_STANDARD, $fk_parent_line=0, $skip_update_total=0, $fk_fournprice=null, $pa_ht=0, $label='', $special_code=0, $array_options=0, $situation_percent=0, $fk_unit = null
				$object->updateline($line->id, $line->desc, $pu, $line->qty, $line->remise_percent, $line->date_start, $line->date_end, $line->tva_tx, $line->localtax1_tx, $line->localtax2_tx, 'HT', $line->info_bits, $line->product_type, $line->fk_parent_line, $line->skip_update_total, 0, $line->pa_ht, $line->label, $line->special_code, $line->array_options, $line->situation_percent, $line->fk_unit);
				break;
		}
	}

	function _getTotalByQty(&$object, $qty, $field_total)
	{
		$total = 0;

		foreach ($object->lines as $line)
		{
			if ($line->qty == $qty)
			{
				$total += $line->{$field_total};
			}
		}

		return $total;
	}

	function _getOutPutLangs(&$object)
	{
		global $conf;

		$object->fetch_thirdparty();

		$outputlangs = new Translate('',$conf);
		$langcode = ( !empty($object->thirdparty->country_code)  ? $object->thirdparty->country_code : (!getDolGlobalString('MAIN_LANG_DEFAULT') ? 'auto' : getDolGlobalString('MAIN_LANG_DEFAULT')));
		$outputlangs->setDefaultLang($langcode);

		return $outputlangs;
	}
