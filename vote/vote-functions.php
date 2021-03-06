<?php
/*
 * O candidato tem dois registros no banco
 * 
 * 1. o usuário dele
 * 2. o projeto dele
 * 
 * O usuário tem os metadados básicos de nome, cpf, uf e setorial
 * 
 * O projeto tem a ficha de candidatura dele
 * 
 * Os votos são registrados contra o projeto, não contra o usuário
 * 
 */ 


// verifica se usuario atual pode votar no candidato, batendo com ID do post da inscricao do candidato
function current_user_can_vote_in_project($project_id) {
	$user = wp_get_current_user();
	return user_can_vote_in_project($user->ID, $project_id);
}

function user_can_vote_in_project($user_id, $project_id) {
	global $wpdb;
	$candidate_id = $wpdb->get_var( $wpdb->prepare("SELECT post_author FROM $wpdb->posts WHERE ID = %s", $project_id) );
	return user_can_vote_in_user($user_id, $candidate_id);
}

// verifica se usuario atual pode votar no candidato, batendo com ID do usuario do candidato
function current_user_can_vote_in_user($candidate_id) {
	$user = wp_get_current_user();
	return user_can_vote_in_user($user->ID, $candidate_id);
	
}

function user_can_vote_in_user($user_id, $candidate_id) {
	$user_area = get_user_meta($user_id, 'uf-setorial', true);
	$candidate_area = get_user_meta($candidate_id, 'uf-setorial', true);
	
	//var_dump($candidate_id, $user_id, $user_area, $candidate_area);
	
	return $user_area == $candidate_area;
}

// verifica se o usuário já votou
// retorna 0 se ainda não votou
// retorna int com o numero de vezes que ja votou
function user_already_voted($user_id) {

	$vote = get_user_meta($user_id, 'vote-project-id', true);
	if (empty($vote))
		return 0;

	return (int) get_user_meta($user_id, 'vote-counter', true);

}
function current_user_already_voted() {
	$user = wp_get_current_user();
	return user_already_voted($user->ID);
}

// verifica se o usuário pode mudar seu voto pela data de inicio da troca
function user_can_change_vote_by_date($user_id) {
	
	$data_inicio_da_troca = get_theme_option('data_inicio_da_troca'); //'2015-08-27' // pode botar no admin

	$hoje = date('Y-m-d');

	// verifica data
	if ($data_inicio_da_troca > $hoje)
		return false;

	return true;
}

// retorna quantas vezes o usuário ainda pode votar
function how_many_current_user_can_vote() {

	$vote_counter = current_user_already_voted();

	$vezes_que_pode_mudar = get_theme_option('vezes_que_pode_mudar_voto');

	// o sistema conta o primeiro voto, se ele pode mudar 1, entao o resultado tem que ser 1
	$restante = ( $vezes_que_pode_mudar - $vote_counter ) + 1;

	if( $restante > 0 )
		return $restante;

	return 0;
}

// verifica se o usuário pode mudar seu voto pela quantidade permitida
function user_can_change_vote_by_counter($user_id) {

	$vote_counter = user_already_voted($user_id);

	$vezes_que_pode_mudar = get_theme_option('vezes_que_pode_mudar_voto'); // se quiser pode botar isso no admin. Isso é vezes que pode mudar, não votar. Então se é igual a 1, o vote-counter vai poder chegar até 2

	// verifica se o numero de vezes se está dentro do permitido
	if ($vote_counter > $vezes_que_pode_mudar) 
		return false;

	return true;
}


function current_user_can_change_vote_by_date() {
	$user = wp_get_current_user();
	return user_can_change_vote_by_date($user->ID);
}

function current_user_can_change_vote_by_counter() {
	$user = wp_get_current_user();
	return user_can_change_vote_by_counter($user->ID);
}


function register_vote($user_id, $project_id) {
	// verifica se pode votar
	if (!user_can_vote_in_project($user_id, $project_id))
		return false;

    $user_voted = user_already_voted($user_id);
    
	// remove todos os votos
	delete_user_meta($user_id, 'vote-project-id');
	
	// registra voto
	add_user_meta($user_id, 'vote-project-id', $project_id);
	
	// incrementa um no vote-counter
	$current_count = get_user_meta($user_id, 'vote-counter', true);

	$current_count = empty($current_count) ? 0 : (int) $current_count;

	$current_count ++;

	update_user_meta($user_id, 'vote-counter', $current_count);

    // envia email
    $user = wp_get_current_user();
    $uf = get_user_meta($user_id, 'UF', true);
    $setorial = get_label_setorial_by_slug(get_user_meta($user_id, 'setorial', true));
    $candidate_name = get_post_meta(get_user_meta($user_id, 'vote-project-id', true), 'candidate-display-name', true);
    $noreply_mail = 'nao-responder-votacultura@cultura.gov.br';
    
    ob_start();
    include('vote-mail.php');
    $mail_content = ob_get_contents();
    ob_end_clean();
    
    $from = sprintf("%s <%s>", $noreply_mail, $noreply_mail);
    $to = array($user->user_email) ;

    $header = "From: $from\r\n";
    $header .= "Content-Type: text/html\r\n";

    $send_message = wp_mail($to, 'Confirmação de inscrição', $mail_content, $header); // TODO verificar envio de email
    if (!$send_message) {
        echo "Erro: não conseguiu enviar email!";
        return false;
    } else {
        return true;
    }
}

// ajax handle
function ajax_register_vote() {

	$response = array();
	$response['success'] = true;
	$response['msg'] = '';

	$user 	       = wp_get_current_user();
	$confirms_vote = isset( $_POST['confirms_vote'] ) ? $_POST['confirms_vote'] : false;

	$data_fim_votacao  = restore_format_date( get_theme_option('data_fim_votacao'));
	$data_inicio_troca = restore_format_date( get_theme_option('data_inicio_da_troca'));

	$vote_counter 	  = how_many_current_user_can_vote();
	$text_change_voto = ( $vote_counter > 1 ) ? 'vezes' : 'vez';
	
	if (is_votacoes_abertas()) {
		
		$canvote = false;

		// se o usuário já votou
		if (current_user_already_voted()) {

			if (current_user_can_change_vote_by_counter()) {
					
				if (current_user_can_change_vote_by_date()) {
					$canvote = true;
					$response['code'] = 'sucess_change_voto';
					$response['msg'] = 'Você pode mudar o voto ' . $vote_counter . ' ' . $text_change_voto . ' até o dia ' . $data_fim_votacao;

				} else {
					$response['success'] = false;
					$response['code'] = 'error_date_change';
					$response['msg'] = 'Atenção!<br>Você já votou! Será possível alterar o voto apenas ' . $vote_counter . ' ' . $text_change_voto . ' entre os dias ' . $data_inicio_troca . ' e ' . $data_fim_votacao;
				}
			
			} else {
				$response['success'] = false;
				$response['code'] = 'error_counter_change';
				$response['msg'] = 'Você não pode mudar seu voto novamente!';
			}

		} else { // se o usuário ainda não votou
			$canvote = true;
		}

		// verifica se pode votar
		if ( user_can_vote_in_project($user->ID, $_POST['project_id'])) {

			if ($canvote) {
				
				// se o usuário confirma o voto
				if( $confirms_vote == true ) {
					if ( register_vote($user->ID, $_POST['project_id'])  ) {
					
						$response['voted_project_id'] = $_POST['project_id'];
					
					} else {
						$response['success'] = false;
						$response['code'] 	= 'error_vote';
						$response['msg'] = 'Erro ao registrar voto';
					}
				}	
			}

		} else {
			$response['success'] = false;
			$response['code'] = 'error_setorial_uf';
			$response['msg'] = 'Você não se inscreveu nesta setorial deste estado. Por isso, não pode votar, somente participar do debate.';
		}
		

	} else {
		$response['success'] = false;
		$response['code'] 	= 'error_vote_closed';
		$response['msg'] = 'A votação não está aberta';
	}
	
	echo json_encode($response);
	
	die;

}

add_action('wp_ajax_register_vote', 'ajax_register_vote');

function is_votacoes_abertas() {

	$hoje = date('Y-m-d');

	if( get_theme_option('data_inicio_votacao') <= $hoje && get_theme_option('data_fim_votacao') >= $hoje )
		return true;

	return false;
	// return get_theme_option('votacoes_abertas');

}


function can_show_invalid_candidates() {

  $hoje = date('Y-m-d');

  if( get_theme_option('data_divulgacao_inabilitados') <= $hoje && !empty(get_theme_option('data_divulgacao_inabilitados')))
    return true;

  return false;
}


function can_show_elected_candidates() {

  $hoje = date('Y-m-d');

  if( get_theme_option('data_divulgacao_eleitos') <= $hoje && !empty(get_theme_option('data_divulgacao_eleitos')))
    return true;

  return false;
}


function is_candidate_invalid( $project_id ) {

    $e = load_evaluation( $project_id );

    if( $e["evaluation-status"] == 'invalid' )
      return true;

    return false; 
}

function get_current_user_vote() {
	$user = wp_get_current_user();
	return get_user_meta($user->ID, 'vote-project-id', true);

}

function get_id_of_users_voted_project($project_id) {
	global $wpdb;
	return $wpdb->get_results($wpdb->prepare("SELECT user_id FROM $wpdb->usermeta WHERE meta_key = 'vote-project-id' AND meta_value = %s", $project_id) );
}

function get_number_of_votes_by_project($project_id) {
	global $wpdb;
	return $wpdb->get_var($wpdb->prepare("SELECT COUNT(user_id) FROM $wpdb->usermeta WHERE meta_key = 'vote-project-id' AND meta_value = %s", $project_id) );
}

function get_number_of_votes_by_project_transient( $project_id ) {
  
  $number_of_votes = get_post_meta( $project_id, 'number_of_votes_project', true );

  if( empty( $number_of_votes ) ) {
    $number_of_votes = get_number_of_votes_by_project( $project_id );
    update_post_meta( $project_id, 'number_of_votes_project', $number_of_votes );
  }

  return $number_of_votes;

}

function get_total_votes_by_uf($uf) {
    global $wpdb;

    $results = $wpdb->get_var($wpdb->prepare(    "SELECT COUNT(u.umeta_id)"
                                                     ."FROM {$wpdb->usermeta} as u "
                                                     ."INNER JOIN {$wpdb->usermeta} as uu ON u.user_id = uu.user_id "
                                                     ."INNER JOIN {$wpdb->usermeta} as uuu ON u.user_id = uuu.user_id "        
                                                     ."WHERE u.meta_key = 'vote-project-id'"
                                                     ."AND uu.meta_key = 'setorial'"
                                                     ."AND uuu.meta_key = 'uf' AND uuu.meta_value = %s", $uf ));
    return $results;
}

function get_count_votes() {
    global $wpdb;

    $results = $wpdb->get_var("SELECT COUNT(u.umeta_id)"
                             ."FROM {$wpdb->usermeta} as u "
                             ."INNER JOIN {$wpdb->usermeta} as uu ON u.user_id = uu.user_id "
                             ."WHERE u.meta_key = 'vote-project-id'"
                             ."AND uu.meta_key = 'setorial'");
    return $results;
}

function get_number_of_votes_setorial_by_uf($uf) {
	global $wpdb;
    $setorais = get_setoriais();

    $count = array();

    foreach( $setorais as $key => $setorial )
    {
        $count[$key] = $wpdb->get_var( $wpdb->prepare("SELECT COUNT(u.umeta_id)"
                                                     ."FROM {$wpdb->usermeta} as u "
                                                     ."INNER JOIN {$wpdb->usermeta} as uu ON u.user_id = uu.user_id "
                                                     ."INNER JOIN {$wpdb->usermeta} as uuu ON u.user_id = uuu.user_id "        
                                                     ."WHERE u.meta_key = 'vote-project-id'"
                                                     ."AND uu.meta_key = 'setorial' AND uu.meta_value = %s "
                                                     ."AND uuu.meta_key = 'uf' AND uuu.meta_value = %s", $key, $uf ));
    }
    return $count;
}


function get_count_votes_genre_uf($uf) {
	global $wpdb;
    
    $votes = array();
    $results = array();
    
    $query = $wpdb->prepare("SELECT COUNT(pm1.meta_value) AS count,"
                                              ."pm1.meta_value AS genero, "
                                              ."pm2.meta_value AS valido "
                                              ."FROM {$wpdb->usermeta} um1 " 
                                              ."INNER JOIN {$wpdb->postmeta} pm1 ON pm1.post_id = um1.meta_value "
                                              ."INNER JOIN {$wpdb->postmeta} pm2 ON pm2.post_id = um1.meta_value "
                                              ."INNER JOIN {$wpdb->posts} p ON p.ID = pm1.post_id "
                                              ."INNER JOIN {$wpdb->usermeta} um2 ON um2.user_id = p.post_author "
                                              ."WHERE um1.meta_key = 'vote-project-id' "
                                              ."AND pm1.meta_key = 'candidate-genre' "
                                              ."AND pm2.meta_key = 'subscription-valid' "
                                              ."AND um2.meta_key = 'UF' "
                                              ."AND um2.meta_value = %s "
                                              ."AND p.post_type = 'projetos' "
                                              ."GROUP BY genero ", $uf
    );
    
    $votes = $wpdb->get_results($query);

    if (!empty($votes)) {
        foreach($votes as $item) {
            if ($item->genero == 'masculino') {
                $results['masculino'] = $item->count;
            } else if ($item->genero == 'feminino')  {
                $results['feminino'] = $item->count;
            }
        }
    }
    
    return $results;
}


function get_number_of_votes_setorial_genre_by_uf($uf) {
	global $wpdb;
    $setorais = get_setoriais();

    $count = array();
    $results = array();

    foreach( $setorais as $key => $setorial )
    {   
        $count[$key] = $wpdb->get_results( $wpdb->prepare("SELECT COUNT(pm1.meta_value) AS count,"
                                              ."um1.meta_value AS candidato_id, "
                                              ."pm2.meta_value AS valido, "
                                              ."um2.meta_value AS setorial, "
                                              ."um3.meta_value AS uf "
                                              ."FROM {$wpdb->usermeta} um1 " 
                                              ."INNER JOIN {$wpdb->postmeta} pm1 ON pm1.post_id = um1.meta_value "
                                              ."INNER JOIN {$wpdb->postmeta} pm2 ON pm2.post_id = um1.meta_value "
                                              ."INNER JOIN {$wpdb->posts} p ON p.ID = pm1.post_id "
                                              ."INNER JOIN {$wpdb->usermeta} um2 ON um2.user_id = p.post_author "
                                              ."INNER JOIN {$wpdb->usermeta} um3 ON um3.user_id = p.post_author "        
                                              ."WHERE um1.meta_key = 'vote-project-id' "
                                              ."AND pm1.meta_key = 'candidate-display-name' "
                                              ."AND pm2.meta_key = 'subscription-valid' "
                                              ."AND um2.meta_key = 'setorial' "
                                              ."AND um2.meta_value = %s "
                                              ."AND um3.meta_key = 'UF' "
                                              ."AND um3.meta_value = %s "
                                              ."AND p.post_type = 'projetos' "
                                              ."GROUP BY nome_candidato ", $setorial, $uf));
        
        if (!empty($count[$key])) {
            foreach($count[$key] as $item) {
                if ($item->genre == 'masculino') {
                    $results[$key]['masculino'] = $item->count;
                } else if ($item->genre == 'feminino')  {
                    $results[$key]['feminino'] = $item->count;
                }
            }
        }
    }
    return $results;
}

function get_count_votes_afrodesc_uf($uf) {
	global $wpdb;
    $setorais = get_setoriais();

    $count = array();
    $results = array();

    foreach( $setorais as $key => $setorial )
    {   
        $count[$key] = $wpdb->get_results( $wpdb->prepare("SELECT COUNT(u.umeta_id) as count, pm.meta_value as afro "
                                                     ."FROM {$wpdb->usermeta} as u "
                                                     ."INNER JOIN {$wpdb->posts} as p ON p.post_author = u.user_id "      
                                                     ."INNER JOIN {$wpdb->usermeta} as uu ON u.user_id = uu.user_id "
                                                     ."INNER JOIN {$wpdb->usermeta} as uuu ON u.user_id = uuu.user_id "
                                                     ."INNER JOIN {$wpdb->postmeta} as pm ON p.ID = pm.post_id "      
                                                     ."WHERE u.meta_key = 'vote-project-id' "
                                                     ."AND uu.meta_key = 'setorial' AND uu.meta_value = %s "        
                                                     ."AND uuu.meta_key = 'uf' AND uuu.meta_value = %s "
                                                     ."AND pm.meta_key = 'candidate-race' "
                                                     ."GROUP BY afro " , $key, $uf ));
        
        if (!empty($count[$key])) {
            foreach($count[$key] as $item) {
                if ($item->afrodesc == 'true') {
                    $results[$key]['afro'] = $item->count;
                } else {
                    $results[$key]['outros'] = $item->count;
                }
            }
        }
    }
    return $results;   
}


function get_votos_afrodesc_estado_setorial($uf = false, $setorial = false) {
    global $wpdb;
    
    $inner = '';
    $where = '';
    $fields = '';
    $args = [];

    if ($uf) {
        $inner .= "INNER JOIN {$wpdb->usermeta} um2 ON um2.user_id = p.post_author  ";
        $where .= "AND um2.meta_key = 'UF' AND um2.meta_value = %s ";
        $fields .= ", um2.meta_value AS uf ";       
        $args[] = $uf;
    }
    if ($setorial) {
        $inner .= "INNER JOIN {$wpdb->usermeta} um3 ON um3.user_id = p.post_author  ";
        $where .= "AND um3.meta_key = 'setorial' AND um3.meta_value = %s ";
        $fields .= ", um3.meta_value AS setorial ";
        $args[] = $setorial;
    }
    

    $query = $wpdb->prepare("SELECT COUNT(um1.meta_value) AS count,"
                                              ."um1.meta_value AS candidato_id, "
                                              ."pm1.meta_value AS afrodesc, "
                                              ."pm2.meta_value AS valido "
                                              . $fields
                                              ."FROM {$wpdb->usermeta} um1 "
                                              ."INNER JOIN {$wpdb->postmeta} pm1 ON pm1.post_id = um1.meta_value "
                                              ."INNER JOIN {$wpdb->postmeta} pm2 ON pm2.post_id = um1.meta_value "
                                              ."INNER JOIN {$wpdb->posts} p ON p.ID = um1.meta_value "
                                              . $inner 
                                              ."WHERE um1.meta_key = 'vote-project-id' "
                                              ."AND pm2.meta_key = 'subscription-valid' "
                                              ."AND p.post_type = 'projetos' "
                                              ."AND pm1.meta_key = 'candidate-race' "
                                              . $where   
                                              ."GROUP BY afrodesc", $args);
    $count = $wpdb->get_results($query);
    
    if (!empty($count)) {
        foreach($count as $item) {
            if ($item->afrodesc == 'true') {
                $results['afrodesc'] = $item->count;
            } else {
                $results['outros'] = $item->count;
            }
        }
    }
    //print $query;
    
    return $results;    
}


function get_number_of_votes_setorial_race_by_uf($uf) {
	global $wpdb;
    $setorais = get_setoriais();

    $count = array();
    $results = array();

    foreach( $setorais as $key => $setorial )
    {

        $count[$key] = $wpdb->get_results( $wpdb->prepare("SELECT COUNT(u.umeta_id) as count, pm.meta_value as race "
                                                     ."FROM {$wpdb->usermeta} as u "
                                                     ."INNER JOIN {$wpdb->posts} as p ON p.post_author = u.user_id "      
                                                     ."INNER JOIN {$wpdb->usermeta} as uu ON u.user_id = uu.user_id "
                                                     ."INNER JOIN {$wpdb->usermeta} as uuu ON u.user_id = uuu.user_id "
                                                     ."INNER JOIN {$wpdb->postmeta} as pm ON p.ID = pm.post_id "      
                                                     ."WHERE u.meta_key = 'vote-project-id' "
                                                     ."AND uu.meta_key = 'setorial' AND uu.meta_value = %s "        
                                                     ."AND uuu.meta_key = 'uf' AND uuu.meta_value = %s "
                                                     ."AND pm.meta_key = 'candidate-race' "
                                                     ."GROUP BY race " , $key, $uf ));
        
        if (!empty($count[$key])) {
            foreach($count[$key] as $item) {
                if ($item->race == 'true') {                  
                    $results[$key]['afro'] = $item->count;
                } else {
                    $results[$key]['outros'] = $item->count;
                }
            }
        }
        return $results;
    }
}

// verifica se o usuário atual é deste estado e setorial
function is_user_this_uf_setorial( $uf_setorial ) {
	$user = wp_get_current_user();
	$user_uf_setorial = strtolower( get_user_meta($user->ID, 'uf-setorial', true) );
	
	if( $user_uf_setorial == $uf_setorial )
		return true;

	return false;
}

function get_inscritos_votaram() {
    global $wpdb;
    $results = $wpdb->get_var("SELECT count(u.ID) "
                             ."FROM {$wpdb->users} u "
                             ."INNER JOIN {$wpdb->usermeta} um ON um.user_id = u.ID "
                             ."WHERE um.meta_key = 'vote-project-id'");
    
    return $results;
}


function get_votos_estado_setorial($uf = false, $setorial = false) {
    global $wpdb;
    
    $inner = '';
    $where = '';
    $args = [];

    if ($uf) {
        $inner .= "INNER JOIN {$wpdb->usermeta} um3 ON um3.user_id = um1.user_id ";
        $where .= "AND um3.meta_key = 'UF' AND um3.meta_value = %s ";
        $args[] = $uf;        
    }
    if ($setorial) {
        $inner .= "INNER JOIN {$wpdb->usermeta} um4 ON um4.user_id = um1.user_id ";
        $where .= "AND um4.meta_key = 'setorial' AND um4.meta_value = %s ";
        $args[] = $setorial;
    }
    
    $results = $wpdb->get_var($wpdb->prepare("SELECT COUNT(um1.umeta_id)"
                                            ."FROM {$wpdb->usermeta} as um1 "
                                            ."INNER JOIN {$wpdb->usermeta} as um2 ON um1.user_id = um2.user_id "
                                            . $inner
                                            ."WHERE um1.meta_key = 'vote-project-id' "
                                            ."AND um2.meta_key = 'setorial' "
                                            . $where, $args));
    
    return $results;    
}


function get_votos_inscritos_votaram_uf_setorial($uf=false, $setorial=false) {
    global $wpdb;

    $inner = '';
    $where = '';
    $args = [];
    
    if ($uf) {
        $inner .= "INNER JOIN {$wpdb->usermeta} um2 ON um2.user_id = u.ID ";
        $where .= "AND um2.meta_key = 'UF' AND um2.meta_value = %s ";
        $args[] = $uf;
    }
    if ($setorial) {
        $inner .= "INNER JOIN {$wpdb->usermeta} um3 ON um3.user_id = u.ID ";
        $where .= "AND um3.meta_key = 'setorial' AND um3.meta_value = %s ";
        $args[] = $setorial;
    }
    
    
    $results = $wpdb->get_var($wpdb->prepare("SELECT count(u.ID) "
                                            ."FROM {$wpdb->users} u "
                                            ."INNER JOIN {$wpdb->usermeta} um1 ON um1.user_id = u.ID "
                                            . $inner
                                            ."WHERE um1.meta_key = 'vote-project-id' "
                                            . $where, $args));
    return $results;
}


function get_maisvotados_setorial_estado($uf=false, $setorial=false) {
    global $wpdb;

    $fields = '';
    $inner = '';
    $where = '';
    $args = [];
    
    if ($uf) {
        $where .= "AND um2.meta_value = %s ";
        $args[] = $uf;
    }
    if ($setorial) {
        $where .= "AND um3.meta_value = %s ";
        $args[] = $setorial;
    }
    //['candidato', 'num_votos', 'genero', 'afrodescendente', 'idade']; 
    $query = $wpdb->prepare("SELECT "
                           ."pm4.meta_value as candidato, "
                           ."count(um1.meta_value) as num_votos, "   
                           ."pm2.meta_value as genero, "
                           ."pm3.meta_value as afrodescendente, "
                           ."pm5.meta_value as habilitado, "    
                           ."um2.meta_value as uf, "
                           ."um3.meta_value as setorial "

                           ."FROM {$wpdb->usermeta} um1 "
                           ."INNER JOIN {$wpdb->postmeta} pm1 ON pm1.post_id = um1.meta_value "
                           ."INNER JOIN {$wpdb->postmeta} pm2 ON pm2.post_id = um1.meta_value "
                           ."INNER JOIN {$wpdb->postmeta} pm3 ON pm3.post_id = um1.meta_value "
                           ."INNER JOIN {$wpdb->postmeta} pm4 ON pm4.post_id = um1.meta_value "
                           ."INNER JOIN {$wpdb->postmeta} pm5 ON pm5.post_id = um1.meta_value "    
                           ."INNER JOIN {$wpdb->posts} p ON p.ID = pm1.post_id "
                           ."INNER JOIN {$wpdb->usermeta} um2 ON um2.user_id = p.post_author "
                           ."INNER JOIN {$wpdb->usermeta} um3 ON um3.user_id = p.post_author "  
                           
                           ."WHERE um1.meta_key = 'vote-project-id' "
                           ."AND p.post_type = 'projetos' "
                           ."AND pm1.meta_key = 'subscription-valid' "
                           ."AND pm2.meta_key = 'candidate-genre' "
                           ."AND pm3.meta_key = 'candidate-race' "
                           ."AND pm4.meta_key = 'candidate-display-name' "
                           ."AND pm5.meta_key = 'evaluation-status' "    
                           ."AND um2.meta_key = 'UF' "
                           ."AND um3.meta_key = 'setorial' "
                           . $where
                           ."GROUP BY candidato "
                           ."ORDER BY uf, setorial, num_votos DESC", $args);
    
    $results = $wpdb->get_results($query);
    
    return $results;
}
