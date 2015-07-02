<?php
/**
 * The Template for displaying all single posts.
 *
 * @package forunssetoriaiscnpc
 *
 */

get_header(); ?>

		<div class="content">

		<?php while ( have_posts() ) : the_post(); ?>

			
				<?php the_title(); ?>
				
				<?php // Lista de candidatos ?>
				<?php 
				$uf = substr($post->post_name, 0, 2);
				
				$setorial = substr($post->post_name, 3);
				
				$candidates = Foruns::get_candidates($uf, $setorial);
				
				$original_post = $post;
				?>
				
					<?php if ($candidates->have_posts()):  ?>
					<?php while ( $candidates->have_posts() ) : $candidates->the_post(); ?>
					
						<div class="candidate" id="<?php the_ID(); ?>">
						
							<?php echo get_post_meta(get_the_ID(), 'candidate-name', true); ?>
							
							<?php // mais detalhes do candidato aqui ?>
							
							<a class="show-candidate-details" data-candidate-id="<?php the_ID(); ?>">Ver mais detalhes</a>
							
							<div class="candidate-details" id="candidate-details-<?php the_ID(); ?>">
							
								Outros detalhes do candidato 
								
							</div>
							
							<br />
							
							<?php if (is_votacoes_abertas() && current_user_can_vote_in_project(get_the_ID())): ?>
								<a class="vote" id="vote-for-<?php the_ID(); ?>" data-project_id="<?php the_ID(); ?>">
									<?php if (get_current_user_vote() == get_the_ID()): ?>
										Voto registrado
									<?php else: ?>
										Votar
									<?php endif; ?>
									
								</a>
							<?php endif; ?>
						
						</div>
					
					<?php endwhile; ?>
					<?php else: ?>
						Nenhum candidato nesta setorial e neste estado
					<?php endif; ?>
					
					<?php $post = $original_post; ?>
				
				<?php // Discussão ?>
				<h2>Debate</h2>
				
				<?php
				// If comments are open or we have at least one comment, load up the comment template
				if ( comments_open() || '0' != get_comments_number() )
					comments_template( '', true );
				?>
			
			
		<?php endwhile; // end of the loop. ?>

		

		</div><!-- #content .site-content --><!-- #primary .content-area -->

<?php get_sidebar(); ?>
<?php get_footer(); ?>
