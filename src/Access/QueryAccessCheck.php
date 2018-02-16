<?php

namespace Drupal\graphql\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class QueryAccessCheck implements AccessInterface {

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * QueryAccessCheck constructor.
   *
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack.
   */
  public function __construct(RequestStack $requestStack) {
    $this->requestStack = $requestStack;
  }

  /**
   * Checks access.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The currently logged in account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(AccountInterface $account) {
    // If the user has the global permission to execute any query, let them.
    if ($account->hasPermission('execute graphql requests')) {
      return AccessResult::allowed();
    }

    $request = $this->requestStack->getCurrentRequest();
    /** @var \GraphQL\Server\OperationParams[] $operations */
    $operations = (array) $request->attributes->get('operations');

    foreach ($operations as $operation) {
      // If a query was provided by the user, this is an arbitrary query (it's
      // not a persisted query). Hence, we only grant access if the user has the
      // permission to execute any query.
      if ($operation->getOriginalInput('query')) {
        return AccessResult::allowedIfHasPermission($account, 'execute graphql requests');
      }
    }

    // If we reach this point, this is a persisted query.
    return AccessResult::allowedIfHasPermission($account, 'execute persisted graphql requests');
  }

}
