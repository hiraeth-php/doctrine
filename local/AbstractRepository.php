<?php

/**
 * A local abstract repository -- your concrete repositories should extend this and you can add
 * or overload any behavior common to your implementation here.
 *
 * @template T of AbstractEntity
 * @extends Hiraeth\Doctrine\AbstractRepository<T>
 */
abstract class AbstractRepository extends Hiraeth\Doctrine\AbstractRepository
{

}
