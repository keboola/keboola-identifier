from keboola_identifier import Entity, Kid, parse

value = Kid.create("kbc-eu-central-1", {Entity.PROJECT: 9876, Entity.CONFIG: "my config"})
print(value)
print(value.project)
print(value.get(Entity.CONFIG))
print(value.branch)
print(parse(str(value)).to_dict())
assert value == Kid.parse(str(value))
