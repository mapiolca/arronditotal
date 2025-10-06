<?php
        // FR: Empêche Dolibarr d'exiger la vérification CSRF lors de cette exécution.
        // EN: Prevent Dolibarr from requiring a CSRF check during this execution.
        if (!defined("NOCSRFCHECK")) define('NOCSRFCHECK', 1);
        // FR: Évite de régénérer les jetons de sécurité pour cette interface spécifique.
        // EN: Avoid regenerating security tokens for this specific interface.
        if (!defined("NOTOKENRENEWAL")) define('NOTOKENRENEWAL', 1);

        // FR: Charge la configuration globale ainsi que les classes nécessaires aux différents documents.
        // EN: Load the global configuration along with the classes required for the various business documents.
        require('../config.php');
        dol_include_once('/comm/propal/class/propal.class.php');
        dol_include_once('/commande/class/commande.class.php');
        dol_include_once('/compta/facture/class/facture.class.php');
        dol_include_once('/product/class/product.class.php');

        // FR: Récupère le nouveau total désiré soumis par l'utilisateur et le normalise.
        // EN: Retrieve the desired new total submitted by the user and normalize it.
        $newTotal = GETPOST('newTotal');
        $newTotal = price2num($newTotal);

        // FR: Identifie l'objet métier ciblé (propal, commande, facture, ...).
        // EN: Identify the business object being targeted (proposal, order, invoice, ...).
        $fk_object = GETPOST('fk_object', 'int');
        $className = GETPOST('element', 'alpha');
        $className = ucfirst($className);

        // FR: Stoppe l'exécution si la classe attendue n'est pas disponible.
        // EN: Stop execution if the expected class cannot be found.
        if (!class_exists($className)) exit("class $className not found");

        // FR: Instancie l'objet Dolibarr et charge ses données depuis la base.
        // EN: Instantiate the Dolibarr object and load its data from the database.
        $object = new $className($db);
        $object->fetch($fk_object);

	_exitOrNot($object, $className);

        // FR: Détermine si l'arrondi doit se baser sur le HT (B2B) ou le TTC.
        // EN: Determine whether the rounding should rely on VAT excluded (B2B) or VAT included amounts.
        if (getDolGlobalString('ARRONDITOTAL_B2B')) $field_total = 'total_ht';
        else $field_total = 'total_ttc';

        // FR: Coefficient d'ajustement appliqué aux prix unitaires.
        // EN: Adjustment coefficient applied to unit prices.
        $coef = 1;
        if (!getDolGlobalString('ARRONDITOTAL_QTY_NEEDED_TO_UPDATE'))
        {
                // FR: Si aucune quantité particulière n'est ciblée, calcule un coefficient global.
                // EN: When no specific quantity is targeted, compute a global coefficient.
                if (!empty($object->{$field_total}) && doubleval($object->{$field_total}) != 0) {
                        $coef = $newTotal / $object->{$field_total};
                }
        }
        else
        {
                // FR: Quand l'option est activée, ajuste uniquement les lignes avec une quantité précise.
                // EN: When the option is enabled, adjust only the lines with the specified quantity.
                $delta = $object->{$field_total} - $newTotal;
                $totalByQty = _getTotalByQty($object, getDolGlobalString('ARRONDITOTAL_QTY_NEEDED_TO_UPDATE') , $field_total);
                if (!empty($totalByQty) && doubleval($totalByQty) != 0 ) {
                        $coef = ($totalByQty - $delta) / $totalByQty;
                }
        }

        // FR: Prépare les pointeurs nécessaires pour gérer la ligne de rattrapage ainsi que le cache produit.
        // EN: Prepare the pointers used for the adjustment line and the product cache.
        $lastLine = false;
        $lastLineId = 0;
        $lastEligibleLine = false;
        $lastEligibleLineId = 0;
        $productCache = array();
        foreach ($object->lines as $line)
        {
                $lineWasUpdated = false;
                // FR: Calcule le prix unitaire TTC ou HT selon le contexte B2B/B2C.
                // EN: Compute the unit price in VAT inclusive or exclusive mode depending on B2B/B2C context.
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

                $pu = $pu * $coef; // FR: applique le coefficient calculé / EN: apply the calculated coefficient
                $pu = $pu / $tx_tva; // FR: revient au prix HT / EN: convert back to VAT excluded price

                if (getDolGlobalString('ARRONDITOTAL_QTY_NEEDED_TO_UPDATE'))
                {
                        if ($line->qty == getDolGlobalString('ARRONDITOTAL_QTY_NEEDED_TO_UPDATE'))
                        {

                            // FR: Met à jour uniquement les lignes sans code spécial lorsqu'elles correspondent à la quantité ciblée.
                            // EN: Update only non-special lines when they match the targeted quantity.
                            if(empty($line->special_code)) {
                                $pu = _arronditotalProtectMinPrice($line, $pu, $productCache);
                                _updateElementLine($object, $line, $pu);
                                    $lineWasUpdated = true;
                            }

                        }
                }
                else
                {
                    // FR: Sans restriction de quantité, toutes les lignes admissibles sont réévaluées.
                    // EN: Without quantity restrictions, every eligible line is recalculated.
                    if(empty($line->special_code))  {
                        $pu = _arronditotalProtectMinPrice($line, $pu, $productCache);
                        _updateElementLine($object, $line, $pu);
                            $lineWasUpdated = true;
                    }
                }

                if ($lineWasUpdated) {
                        // FR: Mémorise la dernière ligne modifiée et, si possible, celle qui peut absorber le rattrapage.
                        // EN: Remember the last updated line and, when possible, the one eligible for the adjustment.
                        if (empty($line->_arronditotal_min_price_locked)) {
                                $lastEligibleLine = $line;
                                $lastEligibleLineId = $line->id;
                        }
                        $lastLine = $line;
                        $lastLineId = $line->id;
                }
        }

        // FR: Recharge l'objet pour s'assurer que les totaux reflètent les prix réellement sauvegardés.
        // EN: Reload the object to ensure totals reflect the prices that were actually saved.
        $object->fetch($fk_object);

        $linesById = array();
        foreach ($object->lines as $reloadedLine) {
                $linesById[$reloadedLine->id] = $reloadedLine;
        }

        if ($lastEligibleLineId && isset($linesById[$lastEligibleLineId])) {
                $lastEligibleLine = $linesById[$lastEligibleLineId];
        }

        if ($lastLineId && isset($linesById[$lastLineId])) {
                $lastLine = $linesById[$lastLineId];
        }

        if ($lastEligibleLine) {
                // FR: Préfère une ligne qui n'est pas bloquée par le prix minimum pour absorber la différence.
                // EN: Favor a line not locked by the minimum price to absorb the remaining difference.
                $lastLine = $lastEligibleLine;
        }

        if ($lastLine)
        {
                // FR: Ajoute à la ligne finale la différence de centimes restante.
                // EN: Add the remaining cent difference to the final line.
                if (getDolGlobalString('ARRONDITOTAL_B2B')) $tx_tva = 1;
                else $tx_tva = 1 + ($lastLine->tva_tx / 100);

                $diff_compta = $newTotal - $object->{$field_total}; // FR: écart total à combler / EN: total gap to cover
                $diff_compta = $diff_compta / $lastLine->qty; // FR: ramène l'écart à un prix unitaire / EN: convert the gap to unit price
                $pu = $lastLine->subprice * $tx_tva; // FR: repart de l'ancien prix TTC / EN: reuse the previous VAT-included price
                $pu = $pu + $diff_compta;
                $pu = $pu / $tx_tva; // FR: calcule le nouvel HT unitaire / EN: compute the new VAT-excluded unit price

                $pu = _arronditotalProtectMinPrice($lastLine, $pu, $productCache, true);

                _updateElementLine($object, $lastLine, $pu);

                $outputlangs = &_getOutPutLangs($object);
                $object->generateDocument('', $outputlangs);
        }
        else
        {
                // FR: Informe l'utilisateur si aucune ligne ne peut être mise à jour.
                // EN: Inform the user when no line can be updated.
                setEventMessages($langs->trans('arronditotalErrorNoLine'), null, 'errors');
        }

        function _arronditotalProtectMinPrice(&$line, $pu, &$productCache, $forceReload = false)
        {
                // FR: Protège le prix unitaire pour ne pas descendre sous le prix minimum de la ligne.
                // EN: Protect the unit price from going below the line's minimum price.
                $pu = price2num($pu, 'MU');

                $minPrice = _arronditotalGetLineMinPrice($line, $productCache, $forceReload);
                $canIgnore = _arronditotalCanIgnoreMinPrice($line);

                $line->_arronditotal_min_price = $minPrice;
                $line->_arronditotal_can_ignore_min_price = $canIgnore;
                $line->_arronditotal_min_price_locked = 0;

                if ($minPrice !== null && !$canIgnore && price2num($pu, 'MU') < $minPrice) {
                        // FR: Fige le prix à son minimum lorsque l'utilisateur n'a pas le droit de le dépasser.
                        // EN: Lock the price at its minimum when the user lacks the permission to go below.
                        $pu = $minPrice;
                        $line->_arronditotal_min_price_locked = 1;
                }

                return $pu;
        }

        function _arronditotalGetLineMinPrice(&$line, &$productCache, $forceReload = false)
        {
                // FR: Charge et met en cache le prix minimum du produit lié à la ligne.
                // EN: Load and cache the minimum price for the product attached to the line.
                global $db;

                if (empty($line->fk_product)) return null;

                if ($forceReload || !array_key_exists($line->fk_product, $productCache)) {
                        $product = new Product($db);
                        if ($product->fetch($line->fk_product) > 0) {
                                // FR: Stocke le prix minimum pour éviter de multiples requêtes.
                                // EN: Store the minimum price to avoid multiple queries.
                                $productCache[$line->fk_product] = array(
                                        'price_min' => price2num($product->price_min, 'MU')
                                );
                        } else {
                                $productCache[$line->fk_product] = null;
                        }
                }

                $productData = $productCache[$line->fk_product];
                if (!empty($productData) && isset($productData['price_min'])) {
                        return price2num($productData['price_min'], 'MU');
                }

                return null;
        }

        function _arronditotalCanIgnoreMinPrice(&$line)
        {
                // FR: Vérifie si l'utilisateur a le droit d'ignorer le prix minimum pour cette ligne.
                // EN: Check whether the user can ignore the minimum price for this line.
                global $user;

                if (empty($user) || empty($user->rights)) return false;

                $paths = array(
                        array('produit', 'price', 'ignore_min_price'),
                        array('produit', 'ignore_min_price'),
                        array('produit', 'price', 'ignore_price_min'),
                        array('produit', 'ignore_price_min'),
                        array('produit', 'price', 'ignore_price_limit'),
                        array('produit', 'ignore_price_limit')
                );

                if (!empty($line->product_type) && (int) $line->product_type === Product::TYPE_SERVICE) {
                        $paths = array_merge($paths, array(
                                array('service', 'price', 'ignore_min_price'),
                                array('service', 'ignore_min_price'),
                                array('service', 'price', 'ignore_price_min'),
                                array('service', 'ignore_price_min'),
                                array('service', 'price', 'ignore_price_limit'),
                                array('service', 'ignore_price_limit')
                        ));
                }

                foreach ($paths as $path) {
                        // FR: Détecte le premier droit disponible permettant de contourner le prix plancher.
                        // EN: Detect the first available right that allows bypassing the floor price.
                        if (_arronditotalRightPathEnabled($user->rights, $path)) {
                                return true;
                        }
                }

                return false;
        }

        function _arronditotalRightPathEnabled($rights, $path)
        {
                // FR: Parcourt récursivement un chemin de droits pour vérifier s'il est activé.
                // EN: Traverse a rights path recursively to check if it is enabled.
                $cursor = $rights;
                foreach ($path as $segment) {
                        if (is_object($cursor) && isset($cursor->{$segment})) {
                                $cursor = $cursor->{$segment};
                        } elseif (is_array($cursor) && isset($cursor[$segment])) {
                                $cursor = $cursor[$segment];
                        } else {
                                return false;
                        }
                }

                if (is_array($cursor)) {
                        // FR: Considère qu'un tableau non vide correspond à un droit accordé.
                        // EN: Consider a non-empty array as a granted permission.
                        return !empty($cursor);
                }

                return !empty($cursor);
        }

        function _exitOrNot(&$object, $className)
        {
                // FR: Vérifie que l'objet est bien brouillon et compatible avant d'autoriser l'arrondi.
                // EN: Ensure the object is in draft status and compatible before allowing the rounding process.
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

        function _updateElementLine(&$object, &$line, $pu)
        {
                // FR: Met à jour une ligne selon le type d'objet pour refléter le nouveau prix unitaire.
                // EN: Update a line based on the object type to reflect the new unit price.
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
                // FR: Additionne les totaux des lignes correspondant à une quantité donnée.
                // EN: Sum the totals of lines matching a given quantity.
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
                // FR: Détermine la langue de sortie pour régénérer le document après les modifications.
                // EN: Determine the output language to regenerate the document after modifications.
                global $conf;

                $object->fetch_thirdparty();

		$outputlangs = new Translate('',$conf);
		$langcode = ( !empty($object->thirdparty->country_code)  ? $object->thirdparty->country_code : (!getDolGlobalString('MAIN_LANG_DEFAULT') ? 'auto' : getDolGlobalString('MAIN_LANG_DEFAULT')));
		$outputlangs->setDefaultLang($langcode);

		return $outputlangs;
	}
