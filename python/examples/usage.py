from keboola_identifier import Entity, Kid, parse

value = Kid.create("kbc-eu-central-1", {Entity.PROJECT: "9876", Entity.CONFIG: "my config"})
print(value)
print(value.project)
print(value.get(Entity.CONFIG))
print(parse(str(value)))
assert value == Kid.from_string(str(value))
