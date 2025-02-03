<?php

namespace Drupal\custom_rest_api\Plugin\rest\resource;

use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use Drupal\Core\Session\AccountProxyInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Cache\CacheableResponseInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Entity\EntityTypeManagerInterface;



/**
 * Annotation for get method
 *
 * @RestResource(
 *   id = "article_resource",
 *   label = @Translation("Custom Rest GET"),
 *   uri_paths = {
 *     "canonical" = "/api/articles/{id}",
 *     "create" = "/api/articles",
 *   }
 * )
 */
class ArticleRestResource extends ResourceBase {

  /**
   * A current user instance.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;
  
  
   /**
   * Entity manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a Drupal\rest\Plugin\ResourceBase object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param array $serializer_formats
   *   The available serialization formats.
   * @param \Psr\Log\LoggerInterface $logger
   *   A logger instance.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   A current user instance.
   */

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    array $serializer_formats,
    LoggerInterface $logger,
    AccountProxyInterface $current_user,
    EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $serializer_formats, $logger);
    $this->currentUser = $current_user;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->getParameter('serializer.formats'),
      $container->get('logger.factory')->get('custom_rest'),
      $container->get('current_user'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * Responds to GET requests. It will return serialize json format of node
   * object.
   *
   * @param $id
   *   Node id.
   */

  public function get($id = NULL) {
    // If a specific node is requested

    if ($id) {
      $article = Node::load($id);
      if (!$article || $article->getType() != 'article') {
        throw new AccessDeniedHttpException();
      }
      $response = new ResourceResponse($article);
    }
    // Otherwise, fetch all articles
    else {
      $query = $this->entityTypeManager->getStorage('node')->getQuery();
      $query->condition('type', 'article')->accessCheck(TRUE);
      $nids = $query->execute();
      if (!empty($nids)) {
        $articles = Node::loadMultiple($nids);
        $response = new ResourceResponse($articles);
      }
      else {
        $response = new ResourceResponse([]);
      }
    }

    // Caching the GET response
    $cacheability = CacheableMetadata::createFromObject($response);
    $cacheability->addCacheContexts(['url.path', 'user.roles']);
    $response->addCacheableDependency($cacheability);

    return $response;
  }

  /**
   * Authenticate all other HTTP methods.
   */
  private function checkAuthentication() {
    $current_user = \Drupal::currentUser();
    if ($current_user->isAnonymous()) {
      throw new AccessDeniedHttpException('Authentication required.');
    }
  }

  /**
   * POST request handler: Create a new article.
   */
  public function post(Request $request) {
    $this->checkAuthentication();
    $data = json_decode($request->getContent(), TRUE);
    if (empty($data['title']) || empty($data['body'])) {
      throw new BadRequestHttpException('Title and body are required.');
    }
    $node = Node::create([
      'type' => 'article',
      'title' => $data['title'],
      'body' => ['value' => $data['body'], 'format' => 'basic_html'],
      'status' => 1,
    ]);
    $node->save();
    return new ResourceResponse($node, 201);
  }

  /**
   * PUT request handler: Replace an article.
   */
  public function put($id, Request $request) {
    $this->checkAuthentication();
    $node = Node::load($id);
    if (!$node || $node->bundle() !== 'article') {
      throw new NotFoundHttpException('Article not found.');
    }
    $data = json_decode($request->getContent(), TRUE);
    $node->setTitle($data['title']);
    $node->set('body', ['value' => $data['body'], 'format' => 'basic_html']);
    $node->save();
    return new ResourceResponse($node);
  }

  /**
   * PATCH request handler: Update an article.
   */
  public function patch($id, Request $request) {
    $this->checkAuthentication();
    $node = Node::load($id);
    if (!$node || $node->bundle() !== 'article') {
      throw new NotFoundHttpException('Article not found.');
    }
    $data = json_decode($request->getContent(), TRUE);
    if (!empty($data['title'])) {
      $node->setTitle($data['title']);
    }
    if (!empty($data['body'])) {
      $node->set('body', ['value' => $data['body'], 'format' => 'basic_html']);
    }
    $node->save();
    return new ResourceResponse($node);
  }

  /**
   * DELETE request handler: Remove an article.
   */
  public function delete($nid) {
    $this->checkAuthentication();
    $node = Node::load($nid);
    if (!$node || $node->bundle() !== 'article') {
      throw new NotFoundHttpException('Article not found.');
    }
    $node->delete();
    return new ResourceResponse(['message' => 'Article deleted successfully.'], 204);
  }

}

