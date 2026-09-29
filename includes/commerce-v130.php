<?php
declare(strict_types=1);

function dt_commerce_resource_types(): array
{
    return ['recording','release','edition'];
}

function dt_commerce_offer_statuses(): array
{
    return ['draft','active','paused','archived'];
}

function dt_commerce_currency(string $value): string
{
    $value=strtoupper(trim($value));
    if(!preg_match('/^[A-Z]{3}$/',$value))throw new RuntimeException('Currency must be a three-letter ISO code.');
    return $value;
}

function dt_commerce_sku(string $value): string
{
    $value=strtoupper(trim($value));
    $value=preg_replace('/[^A-Z0-9._-]+/','-',$value)??'';
    $value=trim($value,'-');
    if($value===''||strlen($value)>120)throw new RuntimeException('SKU must be between 1 and 120 characters.');
    return $value;
}

function dt_commerce_datetime(?string $value): ?string
{
    $value=trim((string)$value);
    if($value==='')return null;
    try{$date=new DateTimeImmutable($value);}catch(Throwable $e){throw new RuntimeException('Sale date is invalid.');}
    return $date->format('Y-m-d H:i:s');
}

function dt_commerce_resource(PDO $pdo,string $type,int $id): ?array
{
    if($id<1)return null;
    if($type==='recording'){
        $stmt=$pdo->prepare("SELECT rec.id,rec.artist_id,rec.title,rec.recording_status status
            FROM music_recordings_v110 rec WHERE rec.id=? LIMIT 1");
    }elseif($type==='release'){
        $stmt=$pdo->prepare("SELECT r.id,r.artist_id,r.title,r.release_status status
            FROM music_releases_v110 r WHERE r.id=? LIMIT 1");
    }elseif($type==='edition'){
        $stmt=$pdo->prepare("SELECT e.id,r.artist_id,CONCAT(r.title,' — ',e.edition_name) title,
            CASE WHEN e.edition_status='active' THEN r.release_status ELSE 'inactive' END status,
            r.id release_id,e.grants_digital_access
            FROM music_release_editions_v110 e
            INNER JOIN music_releases_v110 r ON r.id=e.release_id
            WHERE e.id=? LIMIT 1");
    }else return null;
    $stmt->execute([$id]);
    $row=$stmt->fetch();
    return is_array($row)?$row:null;
}

function dt_commerce_resource_sellable(PDO $pdo,string $type,int $id,int $artistId): bool
{
    $resource=dt_commerce_resource($pdo,$type,$id);
    if(!$resource||(int)$resource['artist_id']!==$artistId)return false;
    if($type==='release'||$type==='edition')return (string)$resource['status']==='published';
    if($type==='recording'){
        if((string)$resource['status']!=='active')return false;
        $stmt=$pdo->prepare("SELECT 1 FROM music_release_tracks_v110 rt
            INNER JOIN music_releases_v110 r ON r.id=rt.release_id
            WHERE rt.recording_id=? AND r.artist_id=? AND r.release_status='published' LIMIT 1");
        $stmt->execute([$id,$artistId]);
        return (bool)$stmt->fetchColumn();
    }
    return false;
}

function dt_commerce_offer(PDO $pdo,int $offerId): ?array
{
    if($offerId<1)return null;
    $stmt=$pdo->prepare('SELECT * FROM music_offers_v130 WHERE id=? LIMIT 1');
    $stmt->execute([$offerId]);
    $row=$stmt->fetch();
    return is_array($row)?$row:null;
}

function dt_commerce_offer_available(array $offer,?DateTimeImmutable $now=null): bool
{
    if((string)($offer['offer_status']??'')!=='active')return false;
    $now??=new DateTimeImmutable('now');
    $starts=trim((string)($offer['sale_starts_at']??''));
    $ends=trim((string)($offer['sale_ends_at']??''));
    if($starts!==''&&new DateTimeImmutable($starts)>$now)return false;
    if($ends!==''&&new DateTimeImmutable($ends)<=$now)return false;
    return true;
}

function dt_commerce_create_offer(PDO $pdo,int $artistId,array $user,array $input): array
{
    if(!dt_artist_can($pdo,$artistId,(int)($user['id']??0),'commerce'))throw new RuntimeException('Your artist role cannot manage commerce.');
    $resourceType=(string)($input['resource_type']??'');
    $resourceId=(int)($input['resource_id']??0);
    if(!in_array($resourceType,dt_commerce_resource_types(),true)||!dt_commerce_resource($pdo,$resourceType,$resourceId))throw new RuntimeException('Offer resource was not found.');
    $resource=dt_commerce_resource($pdo,$resourceType,$resourceId);
    if((int)($resource['artist_id']??0)!==$artistId)throw new RuntimeException('Offer resource must belong to this artist.');

    $name=trim((string)($input['offer_name']??''));
    if($name===''||mb_strlen($name)>190)throw new RuntimeException('Offer name must be between 1 and 190 characters.');
    $sku=dt_commerce_sku((string)($input['sku']??''));
    $price=(int)($input['price_cents']??-1);
    if($price<0)throw new RuntimeException('Offer price cannot be negative.');
    $currency=dt_commerce_currency((string)($input['currency']??'USD'));
    $entitlement=(string)($input['grants_entitlement_type']??'own');
    if(!in_array($entitlement,dt_entitlement_types(),true))throw new RuntimeException('Offer entitlement type is invalid.');
    $status=(string)($input['offer_status']??'draft');
    if(!in_array($status,dt_commerce_offer_statuses(),true))throw new RuntimeException('Offer status is invalid.');
    $starts=dt_commerce_datetime($input['sale_starts_at']??null);
    $ends=dt_commerce_datetime($input['sale_ends_at']??null);
    if($starts!==null&&$ends!==null&&$ends<=$starts)throw new RuntimeException('Sale end must be after sale start.');
    if($status==='active'&&!dt_commerce_resource_sellable($pdo,$resourceType,$resourceId,$artistId))throw new RuntimeException('Only published music can have an active offer.');

    try{
        $stmt=$pdo->prepare("INSERT INTO music_offers_v130
            (artist_id,created_by_user_id,resource_type,resource_id,offer_name,sku,price_cents,currency,grants_entitlement_type,offer_status,sale_starts_at,sale_ends_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$artistId,(int)$user['id'],$resourceType,$resourceId,$name,$sku,$price,$currency,$entitlement,$status,$starts,$ends]);
    }catch(PDOException $e){
        if((string)$e->getCode()==='23000')throw new RuntimeException('That SKU is already in use.');
        throw $e;
    }
    return dt_commerce_offer($pdo,(int)$pdo->lastInsertId())??throw new RuntimeException('Offer could not be loaded.');
}

function dt_commerce_set_offer_status(PDO $pdo,int $artistId,int $offerId,string $status,array $user): void
{
    if(!dt_artist_can($pdo,$artistId,(int)($user['id']??0),'commerce'))throw new RuntimeException('Your artist role cannot manage commerce.');
    if(!in_array($status,dt_commerce_offer_statuses(),true))throw new RuntimeException('Offer status is invalid.');
    $offer=dt_commerce_offer($pdo,$offerId);
    if(!$offer||(int)$offer['artist_id']!==$artistId)throw new RuntimeException('Offer was not found.');
    if($status==='active'&&!dt_commerce_resource_sellable($pdo,(string)$offer['resource_type'],(int)$offer['resource_id'],$artistId))throw new RuntimeException('Only published music can have an active offer.');
    $pdo->prepare('UPDATE music_offers_v130 SET offer_status=?,updated_at=NOW() WHERE id=? AND artist_id=?')->execute([$status,$offerId,$artistId]);
}

function dt_commerce_artist_offers(PDO $pdo,int $artistId,bool $includeArchived=false): array
{
    $sql='SELECT * FROM music_offers_v130 WHERE artist_id=?';
    if(!$includeArchived)$sql.=" AND offer_status<>'archived'";
    $sql.=' ORDER BY updated_at DESC,id DESC';
    $stmt=$pdo->prepare($sql);
    $stmt->execute([$artistId]);
    return $stmt->fetchAll()?:[];
}

function dt_commerce_public_offers(PDO $pdo,int $limit=100): array
{
    $limit=max(1,min(200,$limit));
    $sql="SELECT o.*,a.name artist_name,a.slug artist_slug
        FROM music_offers_v130 o INNER JOIN artists a ON a.id=o.artist_id
        WHERE o.offer_status='active' AND a.artist_status='active'
          AND (o.sale_starts_at IS NULL OR o.sale_starts_at<=NOW())
          AND (o.sale_ends_at IS NULL OR o.sale_ends_at>NOW())
        ORDER BY o.updated_at DESC,o.id DESC LIMIT ".$limit;
    $rows=$pdo->query($sql)->fetchAll()?:[];
    return array_values(array_filter($rows,static fn(array $row):bool=>dt_commerce_resource_sellable($pdo,(string)$row['resource_type'],(int)$row['resource_id'],(int)$row['artist_id'])));
}

function dt_commerce_order(PDO $pdo,int $orderId): ?array
{
    if($orderId<1)return null;
    $stmt=$pdo->prepare('SELECT * FROM music_orders_v130 WHERE id=? LIMIT 1');
    $stmt->execute([$orderId]);
    $row=$stmt->fetch();
    return is_array($row)?$row:null;
}

function dt_commerce_order_by_checkout_key(PDO $pdo,string $checkoutKey): ?array
{
    $stmt=$pdo->prepare('SELECT * FROM music_orders_v130 WHERE checkout_key=? LIMIT 1');
    $stmt->execute([$checkoutKey]);
    $row=$stmt->fetch();
    return is_array($row)?$row:null;
}

function dt_commerce_order_items(PDO $pdo,int $orderId): array
{
    $stmt=$pdo->prepare('SELECT * FROM music_order_items_v130 WHERE order_id=? ORDER BY id');
    $stmt->execute([$orderId]);
    return $stmt->fetchAll()?:[];
}

function dt_commerce_order_event(PDO $pdo,int $orderId,string $eventType,?int $actorId=null,array $metadata=[]): void
{
    $json=json_encode($metadata,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if($json===false)$json='{}';
    $stmt=$pdo->prepare('INSERT INTO music_order_events_v130 (order_id,event_type,actor_user_id,metadata_json) VALUES (?,?,?,?)');
    $stmt->execute([$orderId,substr(trim($eventType),0,80),$actorId?:null,$json]);
}

function dt_commerce_order_number(): string
{
    return 'DT-'.strtoupper(bin2hex(random_bytes(8)));
}

function dt_commerce_normalize_offer_ids(array $offerIds): array
{
    $ids=[];
    foreach($offerIds as $id){
        $id=(int)$id;
        if($id>0)$ids[$id]=true;
    }
    $ids=array_keys($ids);
    sort($ids,SORT_NUMERIC);
    if(!$ids)throw new RuntimeException('Choose at least one offer.');
    if(count($ids)>50)throw new RuntimeException('Checkout supports at most 50 digital items.');
    return $ids;
}

function dt_commerce_prepare_order(PDO $pdo,array $user,array $offerIds,string $checkoutKey): array
{
    $userId=(int)($user['id']??0);
    if(!dt_library_user_exists($pdo,$userId))throw new RuntimeException('Sign in with an active account to prepare checkout.');
    $checkoutKey=trim($checkoutKey);
    if($checkoutKey===''||mb_strlen($checkoutKey)>190)throw new RuntimeException('Checkout key is required.');
    $ids=dt_commerce_normalize_offer_ids($offerIds);
    $cartHash=hash('sha256',implode(',',$ids));

    $existing=dt_commerce_order_by_checkout_key($pdo,$checkoutKey);
    if($existing){
        if((int)$existing['buyer_user_id']!==$userId||(string)$existing['cart_hash']!==$cartHash)throw new RuntimeException('Checkout idempotency key conflicts with an existing order.');
        return $existing;
    }

    $ownsTransaction=!$pdo->inTransaction();
    if($ownsTransaction)$pdo->beginTransaction();
    try{
        // Lock the unique checkout-key gap before reading offers. This serializes
        // concurrent preparations using the same idempotency key.
        $checkoutLock=$pdo->prepare('SELECT * FROM music_orders_v130 WHERE checkout_key=? FOR UPDATE');
        $checkoutLock->execute([$checkoutKey]);
        $lockedExisting=$checkoutLock->fetch();
        if($lockedExisting){
            if((int)$lockedExisting['buyer_user_id']!==$userId||(string)$lockedExisting['cart_hash']!==$cartHash)throw new RuntimeException('Checkout idempotency key conflicts with an existing order.');
            if($ownsTransaction)$pdo->commit();
            return $lockedExisting;
        }

        $placeholders=implode(',',array_fill(0,count($ids),'?'));
        $stmt=$pdo->prepare("SELECT * FROM music_offers_v130 WHERE id IN ({$placeholders}) FOR UPDATE");
        $stmt->execute($ids);
        $rows=$stmt->fetchAll()?:[];
        $offers=[];foreach($rows as $row)$offers[(int)$row['id']]=$row;
        if(count($offers)!==count($ids))throw new RuntimeException('One or more offers were not found.');

        $currency='';$subtotal=0;
        foreach($ids as $id){
            $offer=$offers[$id];
            if(!dt_commerce_offer_available($offer)||!dt_commerce_resource_sellable($pdo,(string)$offer['resource_type'],(int)$offer['resource_id'],(int)$offer['artist_id']))throw new RuntimeException('One or more offers are not available.');
            if($currency==='' )$currency=(string)$offer['currency'];
            if($currency!==(string)$offer['currency'])throw new RuntimeException('A checkout cannot mix currencies.');
            $subtotal+=(int)$offer['price_cents'];
        }

        $orderNumber=dt_commerce_order_number();
        $expires=(new DateTimeImmutable('+30 minutes'))->format('Y-m-d H:i:s');
        try{
            $insert=$pdo->prepare("INSERT INTO music_orders_v130
                (order_number,checkout_key,cart_hash,buyer_user_id,order_status,currency,subtotal_cents,total_cents,expires_at)
                VALUES (?,?,?,?,'pending',?,?,?,?)");
            // Keep the explicit values visible; subtotal and total are intentionally identical in V1.
            $insert->execute([$orderNumber,$checkoutKey,$cartHash,$userId,$currency,$subtotal,$subtotal,$expires]);
        }catch(PDOException $e){
            if((string)$e->getCode()!=='23000')throw $e;
            throw new RuntimeException('Checkout idempotency key or order number conflicts with existing history.');
        }
        $orderId=(int)$pdo->lastInsertId();

        $item=$pdo->prepare("INSERT INTO music_order_items_v130
            (order_id,offer_id,resource_type,resource_id,offer_name_snapshot,sku_snapshot,unit_price_cents,quantity,entitlement_type)
            VALUES (?,?,?,?,?,?,?,1,?)");
        foreach($ids as $id){
            $offer=$offers[$id];
            $item->execute([
                $orderId,$id,(string)$offer['resource_type'],(int)$offer['resource_id'],
                (string)$offer['offer_name'],(string)$offer['sku'],(int)$offer['price_cents'],
                (string)$offer['grants_entitlement_type'],
            ]);
        }
        dt_commerce_order_event($pdo,$orderId,'order.prepared',$userId,['cart_hash'=>$cartHash,'item_count'=>count($ids)]);
        if($ownsTransaction)$pdo->commit();
        return dt_commerce_order($pdo,$orderId)??throw new RuntimeException('Order could not be loaded.');
    }catch(Throwable $e){
        if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}

function dt_commerce_cancel_order(PDO $pdo,int $orderId,array $user): void
{
    $order=dt_commerce_order($pdo,$orderId);
    if(!$order||(int)$order['buyer_user_id']!==(int)($user['id']??0))throw new RuntimeException('Order was not found.');
    if((string)$order['order_status']!=='pending')throw new RuntimeException('Only pending orders can be cancelled.');
    $pdo->prepare("UPDATE music_orders_v130 SET order_status='cancelled',cancelled_at=NOW(),updated_at=NOW() WHERE id=? AND order_status='pending'")->execute([$orderId]);
    dt_commerce_order_event($pdo,$orderId,'order.cancelled',(int)$user['id']);
}

function dt_commerce_payment_event(PDO $pdo,string $provider,string $eventKey): ?array
{
    $stmt=$pdo->prepare('SELECT * FROM music_payment_events_v130 WHERE provider=? AND provider_event_key=? LIMIT 1');
    $stmt->execute([$provider,$eventKey]);
    $row=$stmt->fetch();
    return is_array($row)?$row:null;
}

/**
 * Trusted payment-provider adapter primitive. Public forms never call this.
 */
function dt_commerce_capture_payment(
    PDO $pdo,
    int $orderId,
    string $provider,
    string $eventKey,
    string $paymentRef,
    int $amountCents,
    string $currency,
    string $payloadHash=''
): array {
    $provider=strtolower(trim($provider));
    $eventKey=trim($eventKey);
    $paymentRef=trim($paymentRef);
    $currency=dt_commerce_currency($currency);
    if($provider===''||strlen($provider)>40||$eventKey===''||mb_strlen($eventKey)>190||$paymentRef===''||mb_strlen($paymentRef)>190)throw new RuntimeException('Payment provider identifiers are required.');
    if($amountCents<0)throw new RuntimeException('Payment amount is invalid.');
    $payloadHash=trim($payloadHash);
    if($payloadHash!==''&&!preg_match('/^[a-f0-9]{64}$/i',$payloadHash))throw new RuntimeException('Payment payload hash is invalid.');

    $existingEvent=dt_commerce_payment_event($pdo,$provider,$eventKey);
    if($existingEvent){
        if((int)$existingEvent['order_id']!==$orderId||(string)$existingEvent['event_type']!=='payment.succeeded'||(int)$existingEvent['amount_cents']!==$amountCents||(string)$existingEvent['currency']!==$currency||(string)$existingEvent['provider_payment_ref']!==$paymentRef)throw new RuntimeException('Payment event idempotency key conflicts with existing history.');
        return dt_commerce_order($pdo,$orderId)??throw new RuntimeException('Order was not found.');
    }

    $ownsTransaction=!$pdo->inTransaction();
    if($ownsTransaction)$pdo->beginTransaction();
    try{
        $stmt=$pdo->prepare('SELECT * FROM music_orders_v130 WHERE id=? FOR UPDATE');
        $stmt->execute([$orderId]);$order=$stmt->fetch();
        if(!$order)throw new RuntimeException('Order was not found.');
        $lockedEvent=dt_commerce_payment_event($pdo,$provider,$eventKey);
        if($lockedEvent){
            if((int)$lockedEvent['order_id']!==$orderId||(string)$lockedEvent['event_type']!=='payment.succeeded'||(int)$lockedEvent['amount_cents']!==$amountCents||(string)$lockedEvent['currency']!==$currency||(string)$lockedEvent['provider_payment_ref']!==$paymentRef)throw new RuntimeException('Payment event idempotency key conflicts with existing history.');
            if($ownsTransaction)$pdo->commit();
            return $order;
        }
        if(in_array((string)$order['order_status'],['cancelled','refunded'],true))throw new RuntimeException('This order cannot accept payment.');
        if((string)$order['order_status']==='pending'&&new DateTimeImmutable((string)$order['expires_at'])<=new DateTimeImmutable('now'))throw new RuntimeException('Checkout has expired; prepare a new order.');
        if((int)$order['total_cents']!==$amountCents||(string)$order['currency']!==$currency)throw new RuntimeException('Payment amount or currency does not match the order snapshot.');
        if(in_array((string)$order['order_status'],['paid','fulfilled'],true)){
            if((string)$order['payment_provider']!==$provider||(string)$order['provider_payment_ref']!==$paymentRef)throw new RuntimeException('Order is already associated with another payment.');
        }

        $insert=$pdo->prepare("INSERT INTO music_payment_events_v130
            (order_id,provider,provider_event_key,provider_payment_ref,event_type,amount_cents,currency,payload_hash)
            VALUES (?,?,?,?, 'payment.succeeded',?,?,?)");
        $insert->execute([$orderId,$provider,$eventKey,$paymentRef,$amountCents,$currency,$payloadHash]);

        if((string)$order['order_status']==='pending'){
            $pdo->prepare("UPDATE music_orders_v130 SET order_status='paid',payment_provider=?,provider_payment_ref=?,paid_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$provider,$paymentRef,$orderId]);
            dt_commerce_order_event($pdo,$orderId,'payment.succeeded',null,['provider'=>$provider,'payment_ref'=>$paymentRef]);
        }
        if($ownsTransaction)$pdo->commit();
        return dt_commerce_order($pdo,$orderId)??throw new RuntimeException('Order could not be loaded.');
    }catch(Throwable $e){
        if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}

function dt_commerce_fulfill_order(PDO $pdo,int $orderId): array
{
    $ownsTransaction=!$pdo->inTransaction();
    if($ownsTransaction)$pdo->beginTransaction();
    try{
        $stmt=$pdo->prepare('SELECT * FROM music_orders_v130 WHERE id=? FOR UPDATE');
        $stmt->execute([$orderId]);$order=$stmt->fetch();
        if(!$order)throw new RuntimeException('Order was not found.');
        if((string)$order['order_status']==='fulfilled'){
            if($ownsTransaction)$pdo->commit();
            return $order;
        }
        if((string)$order['order_status']!=='paid')throw new RuntimeException('Only paid orders can be fulfilled.');

        $items=dt_commerce_order_items($pdo,$orderId);
        if(!$items)throw new RuntimeException('Order has no items to fulfill.');
        foreach($items as $item){
            if((string)$item['fulfillment_status']==='fulfilled'&&(int)($item['entitlement_id']??0)>0)continue;
            $entitlement=dt_entitlement_grant($pdo,[
                'grant_key'=>'purchase:order-'.$orderId.':item-'.(int)$item['id'],
                'user_id'=>(int)$order['buyer_user_id'],
                'resource_type'=>(string)$item['resource_type'],
                'resource_id'=>(int)$item['resource_id'],
                'entitlement_type'=>(string)$item['entitlement_type'],
                'source_type'=>'purchase',
                'source_ref'=>(string)$order['order_number'],
            ]);
            $pdo->prepare("UPDATE music_order_items_v130 SET fulfillment_status='fulfilled',entitlement_id=?,updated_at=NOW() WHERE id=? AND order_id=?")->execute([(int)$entitlement['id'],(int)$item['id'],$orderId]);
        }
        $pdo->prepare("UPDATE music_orders_v130 SET order_status='fulfilled',fulfilled_at=COALESCE(fulfilled_at,NOW()),updated_at=NOW() WHERE id=?")->execute([$orderId]);
        dt_commerce_order_event($pdo,$orderId,'order.fulfilled',null,['item_count'=>count($items)]);
        if($ownsTransaction)$pdo->commit();
        return dt_commerce_order($pdo,$orderId)??throw new RuntimeException('Order could not be loaded.');
    }catch(Throwable $e){
        if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}

/**
 * V1 supports full refunds only. Provider adapters authenticate/authorize the
 * refund event before calling this primitive.
 */
function dt_commerce_refund_order(
    PDO $pdo,
    int $orderId,
    string $provider,
    string $eventKey,
    string $paymentRef,
    int $amountCents,
    string $currency,
    string $payloadHash=''
): array {
    $provider=strtolower(trim($provider));
    $eventKey=trim($eventKey);
    $currency=dt_commerce_currency($currency);
    $existingEvent=dt_commerce_payment_event($pdo,$provider,$eventKey);
    if($existingEvent){
        if((int)$existingEvent['order_id']!==$orderId||(string)$existingEvent['event_type']!=='refund.succeeded'||(int)$existingEvent['amount_cents']!==$amountCents||(string)$existingEvent['currency']!==$currency)throw new RuntimeException('Payment event idempotency key conflicts with existing history.');
        return dt_commerce_order($pdo,$orderId)??throw new RuntimeException('Order was not found.');
    }

    $ownsTransaction=!$pdo->inTransaction();
    if($ownsTransaction)$pdo->beginTransaction();
    try{
        $stmt=$pdo->prepare('SELECT * FROM music_orders_v130 WHERE id=? FOR UPDATE');
        $stmt->execute([$orderId]);$order=$stmt->fetch();
        if(!$order)throw new RuntimeException('Order was not found.');
        $lockedEvent=dt_commerce_payment_event($pdo,$provider,$eventKey);
        if($lockedEvent){
            if((int)$lockedEvent['order_id']!==$orderId||(string)$lockedEvent['event_type']!=='refund.succeeded'||(int)$lockedEvent['amount_cents']!==$amountCents||(string)$lockedEvent['currency']!==$currency||(string)$lockedEvent['provider_payment_ref']!==$paymentRef)throw new RuntimeException('Payment event idempotency key conflicts with existing history.');
            if($ownsTransaction)$pdo->commit();
            return $order;
        }
        if((string)$order['order_status']==='refunded')throw new RuntimeException('Order is already refunded.');
        if(!in_array((string)$order['order_status'],['paid','fulfilled'],true))throw new RuntimeException('Only paid or fulfilled orders can be refunded.');
        if((string)$order['payment_provider']!==$provider||(string)$order['provider_payment_ref']!==$paymentRef)throw new RuntimeException('Refund does not match the captured payment.');
        if((int)$order['total_cents']!==$amountCents||(string)$order['currency']!==$currency)throw new RuntimeException('V1 refunds must match the full order total.');

        $payloadHash=trim($payloadHash);
        if($payloadHash!==''&&!preg_match('/^[a-f0-9]{64}$/i',$payloadHash))throw new RuntimeException('Payment payload hash is invalid.');
        $pdo->prepare("INSERT INTO music_payment_events_v130
            (order_id,provider,provider_event_key,provider_payment_ref,event_type,amount_cents,currency,payload_hash)
            VALUES (?,?,?,?, 'refund.succeeded',?,?,?)")->execute([$orderId,$provider,$eventKey,$paymentRef,$amountCents,$currency,$payloadHash]);

        foreach(dt_commerce_order_items($pdo,$orderId) as $item){
            $entitlementId=(int)($item['entitlement_id']??0);
            if($entitlementId>0)dt_entitlement_revoke($pdo,$entitlementId,null,'order_refund');
            $pdo->prepare("UPDATE music_order_items_v130 SET fulfillment_status='revoked',updated_at=NOW() WHERE id=? AND order_id=?")->execute([(int)$item['id'],$orderId]);
        }
        $pdo->prepare("UPDATE music_orders_v130 SET order_status='refunded',refunded_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$orderId]);
        dt_commerce_order_event($pdo,$orderId,'refund.succeeded',null,['provider'=>$provider,'payment_ref'=>$paymentRef]);
        if($ownsTransaction)$pdo->commit();
        return dt_commerce_order($pdo,$orderId)??throw new RuntimeException('Order could not be loaded.');
    }catch(Throwable $e){
        if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}

function dt_commerce_orders_for_user(PDO $pdo,int $userId): array
{
    $stmt=$pdo->prepare("SELECT o.*,(SELECT COUNT(*) FROM music_order_items_v130 i WHERE i.order_id=o.id) item_count
        FROM music_orders_v130 o WHERE o.buyer_user_id=? ORDER BY o.created_at DESC,o.id DESC");
    $stmt->execute([$userId]);
    return $stmt->fetchAll()?:[];
}
