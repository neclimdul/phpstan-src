<?php declare(strict_types = 1);

namespace PHPStan\Rules\DeadCode;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\ClassPropertiesNode;
use PHPStan\Node\Property\PropertyRead;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\MixedType;
use PHPStan\Type\ObjectType;

/**
 * @implements Rule<ClassPropertiesNode>
 */
class UnusedPrivatePropertyRule implements Rule
{

	public function getNodeType(): string
	{
		return ClassPropertiesNode::class;
	}

	public function processNode(Node $node, Scope $scope): array
	{
		if (!$node->getClass() instanceof Node\Stmt\Class_) {
			return [];
		}
		if (!$scope->isInClass()) {
			throw new \PHPStan\ShouldNotHappenException();
		}
		$classType = new ObjectType($scope->getClassReflection()->getName());

		$properties = [];
		foreach ($node->getProperties() as $property) {
			if (!$property->isPrivate()) {
				continue;
			}
			foreach ($property->props as $propertyProperty) {
				$properties[$propertyProperty->name->toString()] = [
					'read' => false,
					'written' => $propertyProperty->default !== null,
					'node' => $property,
				];
			}
		}

		foreach ($node->getPropertyUsages() as $usage) {
			$fetch = $usage->getFetch();
			if (!$fetch->name instanceof Node\Identifier) {
				continue;
			}
			$propertyName = $fetch->name->toString();
			if (!array_key_exists($propertyName, $properties)) {
				continue;
			}
			if ($fetch instanceof Node\Expr\PropertyFetch) {
				$fetchedOnType = $usage->getScope()->getType($fetch->var);
			} else {
				if (!$fetch->class instanceof Node\Name) {
					continue;
				}

				$fetchedOnType = new ObjectType($usage->getScope()->resolveName($fetch->class));
			}

			if ($classType->isSuperTypeOf($fetchedOnType)->no()) {
				continue;
			}
			if ($fetchedOnType instanceof MixedType) {
				continue;
			}
			if ($usage instanceof PropertyRead) {
				$properties[$propertyName]['read'] = true;
			} else {
				$properties[$propertyName]['written'] = true;
			}
		}

		[$uninitializedProperties] = $node->getUninitializedProperties($scope);

		$errors = [];
		foreach ($properties as $name => $data) {
			$propertyNode = $data['node'];
			if ($propertyNode->isStatic()) {
				$propertyName = sprintf('static property $%s', $name);
			} else {
				$propertyName = sprintf('property $%s', $name);
			}
			if (!$data['read']) {
				if (!$data['written']) {
					$errors[] = RuleErrorBuilder::message(sprintf('Class %s has an unused %s.', $scope->getClassReflection()->getDisplayName(), $propertyName))->line($propertyNode->getStartLine())->build();
				} else {
					$errors[] = RuleErrorBuilder::message(sprintf('Class %s has a write-only %s.', $scope->getClassReflection()->getDisplayName(), $propertyName))->line($propertyNode->getStartLine())->build();
				}
			} elseif (!$data['written'] && !array_key_exists($name, $uninitializedProperties)) {
				$errors[] = RuleErrorBuilder::message(sprintf('Class %s has a read-only %s.', $scope->getClassReflection()->getDisplayName(), $propertyName))->line($propertyNode->getStartLine())->build();
			}
		}

		return $errors;
	}

}